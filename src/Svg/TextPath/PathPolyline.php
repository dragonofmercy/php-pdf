<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\TextPath;

use DragonOfMercy\PhpPdf\Svg\ArcToBezier;
use DragonOfMercy\PhpPdf\Svg\PathCommand\Arc;
use DragonOfMercy\PhpPdf\Svg\PathCommand\ClosePath;
use DragonOfMercy\PhpPdf\Svg\PathCommand\CubicBezier;
use DragonOfMercy\PhpPdf\Svg\PathCommand\LineTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\MoveTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\QuadraticBezier;
use DragonOfMercy\PhpPdf\Svg\SvgPathCommand;

/**
 * Flattens a path-command list into a polyline and answers point + tangent at a
 * given arc-length distance. Curves (cubic/quadratic beziers and arcs) are
 * subdivided into a fixed number of straight segments. Used to lay text along a
 * path. A single open subpath is the supported common case; extra MoveTo
 * subpaths are concatenated (their connecting gap is treated as a segment).
 *
 * @internal
 */
final class PathPolyline
{
    /** @var list<array{x: float, y: float}> */
    private array $points;

    /** @var list<float> cumulative arc length at each point (cum[0] = 0) */
    private array $cumulative;

    private float $totalLength;

    /**
     * @param list<array{x: float, y: float}> $points
     */
    private function __construct(array $points)
    {
        $this->points = $points;
        $this->cumulative = [];
        $total = 0.0;
        $n = count($points);
        for ($i = 0; $i < $n; $i++) {
            if ($i === 0) {
                $this->cumulative[] = 0.0;
                continue;
            }
            $dx = $points[$i]['x'] - $points[$i - 1]['x'];
            $dy = $points[$i]['y'] - $points[$i - 1]['y'];
            $total += sqrt($dx * $dx + $dy * $dy);
            $this->cumulative[] = $total;
        }
        $this->totalLength = $total;
    }

    /**
     * @param list<SvgPathCommand> $commands
     */
    public static function fromCommands(array $commands, int $segmentsPerCurve = 24): self
    {
        /** @var list<array{x: float, y: float}> $points */
        $points = [];
        $cx = 0.0;
        $cy = 0.0;
        $startX = 0.0;
        $startY = 0.0;

        /**
         * @param float $x
         * @param float $y
         */
        $push = static function (float $x, float $y) use (&$points): void {
            /** @var list<array{x: float, y: float}> $points */
            $points[] = ['x' => $x, 'y' => $y];
        };

        foreach ($commands as $cmd) {
            if ($cmd instanceof MoveTo) {
                $push($cmd->x, $cmd->y);
                $cx = $startX = $cmd->x;
                $cy = $startY = $cmd->y;
            } elseif ($cmd instanceof LineTo) {
                $push($cmd->x, $cmd->y);
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof CubicBezier) {
                self::flattenCubic($cx, $cy, $cmd->c1x, $cmd->c1y, $cmd->c2x, $cmd->c2y, $cmd->x, $cmd->y, $segmentsPerCurve, $push);
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof QuadraticBezier) {
                $c1x = $cx + 2.0 / 3.0 * ($cmd->cx - $cx);
                $c1y = $cy + 2.0 / 3.0 * ($cmd->cy - $cy);
                $c2x = $cmd->x + 2.0 / 3.0 * ($cmd->cx - $cmd->x);
                $c2y = $cmd->y + 2.0 / 3.0 * ($cmd->cy - $cmd->y);
                self::flattenCubic($cx, $cy, $c1x, $c1y, $c2x, $c2y, $cmd->x, $cmd->y, $segmentsPerCurve, $push);
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof Arc) {
                $segs = ArcToBezier::approximate($cx, $cy, $cmd->rx, $cmd->ry, $cmd->xAxisRotationDeg, $cmd->largeArc, $cmd->sweep, $cmd->x, $cmd->y);
                $px = $cx;
                $py = $cy;
                foreach ($segs as $seg) {
                    self::flattenCubic($px, $py, $seg[0], $seg[1], $seg[2], $seg[3], $seg[4], $seg[5], $segmentsPerCurve, $push);
                    $px = $seg[4];
                    $py = $seg[5];
                }
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof ClosePath) {
                $push($startX, $startY);
                $cx = $startX;
                $cy = $startY;
            }
        }

        return new self($points);
    }

    public function length(): float
    {
        return $this->totalLength;
    }

    /**
     * Point and tangent angle (degrees CCW from +X, SVG y-down space) at the
     * given arc-length distance, clamped to [0, length].
     *
     * @return array{x: float, y: float, angleDeg: float}
     */
    public function pointAt(float $distance): array
    {
        $n = count($this->points);
        if ($n === 0) {
            return ['x' => 0.0, 'y' => 0.0, 'angleDeg' => 0.0];
        }
        if ($n === 1 || $this->totalLength <= 0.0) {
            return ['x' => $this->points[0]['x'], 'y' => $this->points[0]['y'], 'angleDeg' => 0.0];
        }

        $d = max(0.0, min($distance, $this->totalLength));

        $i = $n - 2;
        for ($j = 1; $j < $n; $j++) {
            if ($this->cumulative[$j] >= $d) {
                $i = $j - 1;
                break;
            }
        }

        $segStart = $this->cumulative[$i];
        $segEnd = $this->cumulative[$i + 1];
        $segLen = $segEnd - $segStart;
        $t = $segLen > 0.0 ? ($d - $segStart) / $segLen : 0.0;

        $ax = $this->points[$i]['x'];
        $ay = $this->points[$i]['y'];
        $bx = $this->points[$i + 1]['x'];
        $by = $this->points[$i + 1]['y'];

        return [
            'x' => $ax + ($bx - $ax) * $t,
            'y' => $ay + ($by - $ay) * $t,
            'angleDeg' => rad2deg(atan2($by - $ay, $bx - $ax)),
        ];
    }

    /**
     * @param callable(float, float): void $push
     */
    private static function flattenCubic(
        float $x0, float $y0, float $x1, float $y1, float $x2, float $y2, float $x3, float $y3,
        int $segments, callable $push,
    ): void {
        for ($s = 1; $s <= $segments; $s++) {
            $t = $s / $segments;
            $mt = 1.0 - $t;
            $a = $mt * $mt * $mt;
            $b = 3.0 * $mt * $mt * $t;
            $c = 3.0 * $mt * $t * $t;
            $e = $t * $t * $t;
            $push(
                $a * $x0 + $b * $x1 + $c * $x2 + $e * $x3,
                $a * $y0 + $b * $y1 + $c * $y2 + $e * $y3,
            );
        }
    }
}
