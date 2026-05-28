<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Marker;

use DragonOfMercy\PhpPdf\Svg\ArcToBezier;
use DragonOfMercy\PhpPdf\Svg\PathCommand\Arc;
use DragonOfMercy\PhpPdf\Svg\PathCommand\ClosePath;
use DragonOfMercy\PhpPdf\Svg\PathCommand\CubicBezier;
use DragonOfMercy\PhpPdf\Svg\PathCommand\LineTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\MoveTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\QuadraticBezier;
use DragonOfMercy\PhpPdf\Svg\SvgLine;
use DragonOfMercy\PhpPdf\Svg\SvgPath;
use DragonOfMercy\PhpPdf\Svg\SvgPolygon;
use DragonOfMercy\PhpPdf\Svg\SvgPolyline;
use DragonOfMercy\PhpPdf\Svg\SvgShape;

/**
 * Walks a shape's geometry and returns the (point, tangentAngleDeg, kind)
 * triples where markers should be placed (start / mid / end). Tangent angles
 * are degrees counterclockwise from the positive X axis.
 *
 * @internal
 */
final class MarkerPositioner
{
    /** @return list<MarkerPosition> */
    public static function positionsFor(SvgShape $shape): array
    {
        if ($shape instanceof SvgLine) {
            return self::positionsForLine($shape);
        }
        if ($shape instanceof SvgPolyline) {
            return self::positionsForPolyline($shape->points, closed: false);
        }
        if ($shape instanceof SvgPolygon) {
            return self::positionsForPolyline($shape->points, closed: true);
        }
        if ($shape instanceof SvgPath) {
            return self::positionsForPath($shape);
        }
        return [];
    }

    /** @return list<MarkerPosition> */
    private static function positionsForLine(SvgLine $line): array
    {
        $angle = self::angleDeg($line->x2 - $line->x1, $line->y2 - $line->y1);
        return [
            new MarkerPosition($line->x1, $line->y1, $angle, MarkerKind::START),
            new MarkerPosition($line->x2, $line->y2, $angle, MarkerKind::END),
        ];
    }

    /**
     * @param list<array{0: float, 1: float}> $points
     * @return list<MarkerPosition>
     */
    private static function positionsForPolyline(array $points, bool $closed): array
    {
        $n = count($points);
        if ($n === 0) {
            return [];
        }
        if ($n === 1) {
            return [new MarkerPosition($points[0][0], $points[0][1], 0.0, MarkerKind::START)];
        }

        // Build segment angles between consecutive vertices.
        $segments = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $segments[] = self::angleDeg($points[$i + 1][0] - $points[$i][0], $points[$i + 1][1] - $points[$i][1]);
        }
        if ($closed) {
            $segments[] = self::angleDeg($points[0][0] - $points[$n - 1][0], $points[0][1] - $points[$n - 1][1]);
        }

        $out = [];
        $firstAngle = $closed ? self::bisector($segments[count($segments) - 1], $segments[0]) : $segments[0];
        $out[] = new MarkerPosition($points[0][0], $points[0][1], $firstAngle, MarkerKind::START);

        for ($i = 1; $i < $n - 1; $i++) {
            $angle = self::bisector($segments[$i - 1], $segments[$i]);
            $out[] = new MarkerPosition($points[$i][0], $points[$i][1], $angle, MarkerKind::MID);
        }

        $lastAngle = $closed ? self::bisector($segments[$n - 2], $segments[$n - 1]) : $segments[$n - 2];
        $out[] = new MarkerPosition($points[$n - 1][0], $points[$n - 1][1], $lastAngle, MarkerKind::END);

        return $out;
    }

    /** @return list<MarkerPosition> */
    private static function positionsForPath(SvgPath $path): array
    {
        // Parallel arrays for knot data - avoids float|null ambiguity from mutable array shapes.
        /** @var list<float> $kx */
        $kx = [];
        /** @var list<float> $ky */
        $ky = [];
        /** @var list<float|null> $kIn */
        $kIn = [];
        /** @var list<float|null> $kOut */
        $kOut = [];

        $cx = 0.0;
        $cy = 0.0;
        $subpathStartIndex = null;

        foreach ($path->commands as $cmd) {
            if ($cmd instanceof MoveTo) {
                $kx[] = $cmd->x;
                $ky[] = $cmd->y;
                $kIn[] = null;
                $kOut[] = null;
                $subpathStartIndex = count($kx) - 1;
                $cx = $cmd->x;
                $cy = $cmd->y;
                continue;
            }
            if ($cmd instanceof LineTo) {
                $angle = self::angleDeg($cmd->x - $cx, $cmd->y - $cy);
                if ($kx !== []) {
                    $kOut[count($kOut) - 1] = $angle;
                }
                $kx[] = $cmd->x;
                $ky[] = $cmd->y;
                $kIn[] = $angle;
                $kOut[] = null;
                $cx = $cmd->x;
                $cy = $cmd->y;
                continue;
            }
            if ($cmd instanceof CubicBezier) {
                $outAngle = self::angleDeg($cmd->c1x - $cx, $cmd->c1y - $cy);
                $inAngle = self::angleDeg($cmd->x - $cmd->c2x, $cmd->y - $cmd->c2y);
                if ($kx !== []) {
                    $kOut[count($kOut) - 1] = $outAngle;
                }
                $kx[] = $cmd->x;
                $ky[] = $cmd->y;
                $kIn[] = $inAngle;
                $kOut[] = null;
                $cx = $cmd->x;
                $cy = $cmd->y;
                continue;
            }
            if ($cmd instanceof QuadraticBezier) {
                $outAngle = self::angleDeg($cmd->cx - $cx, $cmd->cy - $cy);
                $inAngle = self::angleDeg($cmd->x - $cmd->cx, $cmd->y - $cmd->cy);
                if ($kx !== []) {
                    $kOut[count($kOut) - 1] = $outAngle;
                }
                $kx[] = $cmd->x;
                $ky[] = $cmd->y;
                $kIn[] = $inAngle;
                $kOut[] = null;
                $cx = $cmd->x;
                $cy = $cmd->y;
                continue;
            }
            if ($cmd instanceof Arc) {
                $arcSegs = ArcToBezier::approximate($cx, $cy, $cmd->rx, $cmd->ry, $cmd->xAxisRotationDeg, $cmd->largeArc, $cmd->sweep, $cmd->x, $cmd->y);
                if ($arcSegs !== []) {
                    // Set outAngle of previous knot to direction at start of first sub-bezier.
                    [$b1x, $b1y] = $arcSegs[0];
                    if ($kx !== []) {
                        $kOut[count($kOut) - 1] = self::angleDeg($b1x - $cx, $b1y - $cy);
                    }
                    // Compute inAngle from the LAST sub-bezier's c2 -> endpoint direction.
                    $last = $arcSegs[count($arcSegs) - 1];
                    $inAngle = self::angleDeg($cmd->x - $last[2], $cmd->y - $last[3]);
                } else {
                    // Degenerate arc (zero-length): use direction from current pen to endpoint.
                    $inAngle = self::angleDeg($cmd->x - $cx, $cmd->y - $cy);
                    if ($kx !== []) {
                        $kOut[count($kOut) - 1] = $inAngle;
                    }
                }
                // Push exactly ONE knot at the arc's endpoint (one command = one knot).
                $kx[] = $cmd->x;
                $ky[] = $cmd->y;
                $kIn[] = $inAngle;
                $kOut[] = null;
                $cx = $cmd->x;
                $cy = $cmd->y;
                continue;
            }
            if ($cmd instanceof ClosePath) {
                if ($subpathStartIndex !== null) {
                    $sx = $kx[$subpathStartIndex];
                    $sy = $ky[$subpathStartIndex];
                    $angle = self::angleDeg($sx - $cx, $sy - $cy);
                    if ($kx !== []) {
                        $kOut[count($kOut) - 1] = $angle;
                    }
                    $kIn[$subpathStartIndex] = $angle;
                    $cx = $sx;
                    $cy = $sy;
                }
                continue;
            }
        }

        $n = count($kx);
        if ($n === 0) {
            return [];
        }

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $kind = match (true) {
                $i === 0 => MarkerKind::START,
                $i === $n - 1 => MarkerKind::END,
                default => MarkerKind::MID,
            };
            $out[] = new MarkerPosition($kx[$i], $ky[$i], self::knotAngle($kIn[$i], $kOut[$i]), $kind);
        }
        return $out;
    }

    private static function angleDeg(float $dx, float $dy): float
    {
        if ($dx === 0.0 && $dy === 0.0) {
            return 0.0;
        }
        return rad2deg(atan2($dy, $dx));
    }

    private static function bisector(float $a, float $b): float
    {
        $diff = $b - $a;
        while ($diff > 180.0) {
            $diff -= 360.0;
        }
        while ($diff <= -180.0) {
            $diff += 360.0;
        }
        return $a + $diff / 2.0;
    }

    private static function knotAngle(?float $in, ?float $out): float
    {
        if ($in === null && $out === null) {
            return 0.0;
        }
        if ($in === null) {
            return $out;
        }
        if ($out === null) {
            return $in;
        }
        return self::bisector($in, $out);
    }
}
