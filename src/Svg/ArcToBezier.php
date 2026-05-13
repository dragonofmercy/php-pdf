<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * Endpoint-arc to cubic-Bezier approximation, per W3C SVG 1.1 implementation
 * note F.6 (https://www.w3.org/TR/SVG11/implnotes.html#ArcImplementationNotes).
 * Splits the arc into segments no longer than pi/2 radians and emits one cubic
 * per segment, using alpha = 4/3 * tan(delta/4) as the unit-circle control
 * point distance, then transforming back to ellipse + rotation + translation.
 */
final class ArcToBezier
{
    /**
     * @return list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}>
     */
    public static function approximate(
        float $x1, float $y1,
        float $rx, float $ry, float $phiDeg,
        bool $largeArc, bool $sweep,
        float $x2, float $y2,
    ): array {
        if ($rx == 0.0 || $ry == 0.0 || ($x1 === $x2 && $y1 === $y2)) {
            return [[$x1, $y1, $x2, $y2, $x2, $y2]];
        }
        $rx = abs($rx);
        $ry = abs($ry);
        $phi = deg2rad($phiDeg);
        $cosPhi = cos($phi);
        $sinPhi = sin($phi);

        $dx = ($x1 - $x2) / 2.0;
        $dy = ($y1 - $y2) / 2.0;
        $x1p = $cosPhi * $dx + $sinPhi * $dy;
        $y1p = -$sinPhi * $dx + $cosPhi * $dy;

        $lambda = ($x1p * $x1p) / ($rx * $rx) + ($y1p * $y1p) / ($ry * $ry);
        if ($lambda > 1.0) {
            $s = sqrt($lambda);
            $rx *= $s;
            $ry *= $s;
        }

        $sign = ($largeArc === $sweep) ? -1.0 : 1.0;
        $num = $rx * $rx * $ry * $ry - $rx * $rx * $y1p * $y1p - $ry * $ry * $x1p * $x1p;
        $den = $rx * $rx * $y1p * $y1p + $ry * $ry * $x1p * $x1p;
        $factor = $den > 0.0 ? $sign * sqrt(max(0.0, $num / $den)) : 0.0;
        $cxp = $factor * $rx * $y1p / $ry;
        $cyp = $factor * -$ry * $x1p / $rx;

        $mx = ($x1 + $x2) / 2.0;
        $my = ($y1 + $y2) / 2.0;
        $cx = $cosPhi * $cxp - $sinPhi * $cyp + $mx;
        $cy = $sinPhi * $cxp + $cosPhi * $cyp + $my;

        $theta1 = self::angle(1.0, 0.0, ($x1p - $cxp) / $rx, ($y1p - $cyp) / $ry);
        $deltaTheta = self::angle(
            ($x1p - $cxp) / $rx, ($y1p - $cyp) / $ry,
            (-$x1p - $cxp) / $rx, (-$y1p - $cyp) / $ry,
        );

        if (!$sweep && $deltaTheta > 0.0) {
            $deltaTheta -= 2.0 * M_PI;
        } elseif ($sweep && $deltaTheta < 0.0) {
            $deltaTheta += 2.0 * M_PI;
        }

        $segments = max(1, (int) ceil(abs($deltaTheta) / (M_PI / 2.0)));
        $delta = $deltaTheta / $segments;
        $alpha = 4.0 / 3.0 * tan($delta / 4.0);

        $beziers = [];
        for ($i = 0; $i < $segments; $i++) {
            $theta = $theta1 + $i * $delta;
            $thetaNext = $theta1 + ($i + 1) * $delta;
            $cosA = cos($theta);
            $sinA = sin($theta);
            $cosB = cos($thetaNext);
            $sinB = sin($thetaNext);

            $u1x = $cosA - $alpha * $sinA;
            $u1y = $sinA + $alpha * $cosA;
            $u2x = $cosB + $alpha * $sinB;
            $u2y = $sinB - $alpha * $cosB;
            $u3x = $cosB;
            $u3y = $sinB;

            [$c1x, $c1y] = self::transformPoint($u1x, $u1y, $rx, $ry, $cosPhi, $sinPhi, $cx, $cy);
            [$c2x, $c2y] = self::transformPoint($u2x, $u2y, $rx, $ry, $cosPhi, $sinPhi, $cx, $cy);
            [$ex, $ey] = self::transformPoint($u3x, $u3y, $rx, $ry, $cosPhi, $sinPhi, $cx, $cy);
            $beziers[] = [$c1x, $c1y, $c2x, $c2y, $ex, $ey];
        }
        return $beziers;
    }

    private static function angle(float $ux, float $uy, float $vx, float $vy): float
    {
        $dot = $ux * $vx + $uy * $vy;
        $len = sqrt(($ux * $ux + $uy * $uy) * ($vx * $vx + $vy * $vy));
        if ($len == 0.0) {
            return 0.0;
        }
        $clamped = max(-1.0, min(1.0, $dot / $len));
        $sign = ($ux * $vy - $uy * $vx) < 0.0 ? -1.0 : 1.0;
        return $sign * acos($clamped);
    }

    /**
     * @return array{0: float, 1: float}
     */
    private static function transformPoint(
        float $x, float $y,
        float $rx, float $ry,
        float $cosPhi, float $sinPhi,
        float $cx, float $cy,
    ): array {
        $xe = $x * $rx;
        $ye = $y * $ry;
        return [$cosPhi * $xe - $sinPhi * $ye + $cx, $sinPhi * $xe + $cosPhi * $ye + $cy];
    }
}
