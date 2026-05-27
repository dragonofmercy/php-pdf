<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DragonOfMercy\PhpPdf\Svg\PathCommand\Arc;
use DragonOfMercy\PhpPdf\Svg\PathCommand\ClosePath;
use DragonOfMercy\PhpPdf\Svg\PathCommand\CubicBezier;
use DragonOfMercy\PhpPdf\Svg\PathCommand\LineTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\MoveTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\QuadraticBezier;

/**
 * Tight geometric bounding box of a shape in its own local coordinate space
 * (before its transform). Used to build the objectBoundingBox gradient matrix.
 * Stroke width is not included, per SVG objectBoundingBox semantics.
 *
 * @internal
 */
final readonly class BoundingBox
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {}

    public function isDegenerate(): bool
    {
        return $this->width <= 0.0 || $this->height <= 0.0;
    }

    public static function of(SvgShape $shape): self
    {
        return match (true) {
            $shape instanceof SvgRect     => new self($shape->x, $shape->y, $shape->width, $shape->height),
            $shape instanceof SvgCircle   => new self($shape->cx - $shape->r, $shape->cy - $shape->r, 2.0 * $shape->r, 2.0 * $shape->r),
            $shape instanceof SvgEllipse  => new self($shape->cx - $shape->rx, $shape->cy - $shape->ry, 2.0 * $shape->rx, 2.0 * $shape->ry),
            $shape instanceof SvgLine     => self::fromPoints([[$shape->x1, $shape->y1], [$shape->x2, $shape->y2]]),
            $shape instanceof SvgPolygon  => self::fromPoints($shape->points),
            $shape instanceof SvgPolyline => self::fromPoints($shape->points),
            $shape instanceof SvgPath     => self::fromPath($shape),
            default                       => new self(0.0, 0.0, 0.0, 0.0),
        };
    }

    /**
     * @param list<array{0: float, 1: float}> $points
     */
    private static function fromPoints(array $points): self
    {
        if ($points === []) {
            return new self(0.0, 0.0, 0.0, 0.0);
        }
        $minX = $maxX = $points[0][0];
        $minY = $maxY = $points[0][1];
        foreach ($points as [$px, $py]) {
            $minX = min($minX, $px);
            $maxX = max($maxX, $px);
            $minY = min($minY, $py);
            $maxY = max($maxY, $py);
        }
        return new self($minX, $minY, $maxX - $minX, $maxY - $minY);
    }

    private static function fromPath(SvgPath $path): self
    {
        $minX = null;
        $maxX = null;
        $minY = null;
        $maxY = null;
        $cx = 0.0;
        $cy = 0.0;
        $accX = static function (float $v) use (&$minX, &$maxX): void {
            $minX = $minX === null ? $v : min($minX, $v);
            $maxX = $maxX === null ? $v : max($maxX, $v);
        };
        $accY = static function (float $v) use (&$minY, &$maxY): void {
            $minY = $minY === null ? $v : min($minY, $v);
            $maxY = $maxY === null ? $v : max($maxY, $v);
        };
        foreach ($path->commands as $cmd) {
            if ($cmd instanceof MoveTo || $cmd instanceof LineTo) {
                $accX($cmd->x);
                $accY($cmd->y);
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof CubicBezier) {
                [$xlo, $xhi] = self::cubicRange($cx, $cmd->c1x, $cmd->c2x, $cmd->x);
                [$ylo, $yhi] = self::cubicRange($cy, $cmd->c1y, $cmd->c2y, $cmd->y);
                $accX($xlo);
                $accX($xhi);
                $accY($ylo);
                $accY($yhi);
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof QuadraticBezier) {
                $c1x = $cx + (2.0 / 3.0) * ($cmd->cx - $cx);
                $c1y = $cy + (2.0 / 3.0) * ($cmd->cy - $cy);
                $c2x = $cmd->x + (2.0 / 3.0) * ($cmd->cx - $cmd->x);
                $c2y = $cmd->y + (2.0 / 3.0) * ($cmd->cy - $cmd->y);
                [$xlo, $xhi] = self::cubicRange($cx, $c1x, $c2x, $cmd->x);
                [$ylo, $yhi] = self::cubicRange($cy, $c1y, $c2y, $cmd->y);
                $accX($xlo);
                $accX($xhi);
                $accY($ylo);
                $accY($yhi);
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof Arc) {
                foreach (ArcToBezier::approximate($cx, $cy, $cmd->rx, $cmd->ry, $cmd->xAxisRotationDeg, $cmd->largeArc, $cmd->sweep, $cmd->x, $cmd->y) as [$b1x, $b1y, $b2x, $b2y, $ex, $ey]) {
                    [$xlo, $xhi] = self::cubicRange($cx, $b1x, $b2x, $ex);
                    [$ylo, $yhi] = self::cubicRange($cy, $b1y, $b2y, $ey);
                    $accX($xlo);
                    $accX($xhi);
                    $accY($ylo);
                    $accY($yhi);
                    $cx = $ex;
                    $cy = $ey;
                }
            } elseif ($cmd instanceof ClosePath) {
                continue;
            }
        }
        if ($minX === null || $maxX === null || $minY === null || $maxY === null) {
            return new self(0.0, 0.0, 0.0, 0.0);
        }
        return new self($minX, $minY, $maxX - $minX, $maxY - $minY);
    }

    /**
     * Min and max of a cubic Bezier on one axis over t in [0,1], including the
     * endpoints and any interior derivative roots.
     *
     * @return array{0: float, 1: float}
     */
    private static function cubicRange(float $a0, float $a1, float $a2, float $a3): array
    {
        $lo = min($a0, $a3);
        $hi = max($a0, $a3);
        $ca = -$a0 + 3.0 * $a1 - 3.0 * $a2 + $a3;
        $cb = 2.0 * ($a0 - 2.0 * $a1 + $a2);
        $cc = $a1 - $a0;
        foreach (self::quadraticRoots($ca, $cb, $cc) as $t) {
            if ($t <= 0.0 || $t >= 1.0) {
                continue;
            }
            $mt = 1.0 - $t;
            $v = $mt * $mt * $mt * $a0
                + 3.0 * $mt * $mt * $t * $a1
                + 3.0 * $mt * $t * $t * $a2
                + $t * $t * $t * $a3;
            $lo = min($lo, $v);
            $hi = max($hi, $v);
        }
        return [$lo, $hi];
    }

    /**
     * @return list<float>
     */
    private static function quadraticRoots(float $a, float $b, float $c): array
    {
        if (abs($a) < 1e-12) {
            if (abs($b) < 1e-12) {
                return [];
            }
            return [-$c / $b];
        }
        $disc = $b * $b - 4.0 * $a * $c;
        if ($disc < 0.0) {
            return [];
        }
        $sq = sqrt($disc);
        return [(-$b + $sq) / (2.0 * $a), (-$b - $sq) / (2.0 * $a)];
    }
}
