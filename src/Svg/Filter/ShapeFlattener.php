<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\ArcToBezier;
use DragonOfMercy\PhpPdf\Svg\PathCommand\Arc;
use DragonOfMercy\PhpPdf\Svg\PathCommand\ClosePath;
use DragonOfMercy\PhpPdf\Svg\PathCommand\CubicBezier;
use DragonOfMercy\PhpPdf\Svg\PathCommand\LineTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\MoveTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\QuadraticBezier;
use DragonOfMercy\PhpPdf\Svg\SvgCircle;
use DragonOfMercy\PhpPdf\Svg\SvgEllipse;
use DragonOfMercy\PhpPdf\Svg\SvgLine;
use DragonOfMercy\PhpPdf\Svg\SvgMatrix;
use DragonOfMercy\PhpPdf\Svg\SvgPath;
use DragonOfMercy\PhpPdf\Svg\SvgPathCommand;
use DragonOfMercy\PhpPdf\Svg\SvgPolygon;
use DragonOfMercy\PhpPdf\Svg\SvgPolyline;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use DragonOfMercy\PhpPdf\Svg\SvgShape;

/**
 * Flattens an SVG shape into device-space polygon rings for the filter
 * rasterizer. Each shape is first reduced to a list of path commands using the
 * same geometry construction as the vector renderer (rect re/rounded-corner
 * arcs, ellipse four-kappa cubics, polygon/polyline lines, path verbatim), then
 * the commands are walked into per-subpath rings (MoveTo opens a ring,
 * ClosePath closes it), curves subdivided into straight segments, and finally
 * every point is mapped through the supplied device matrix.
 *
 * @internal
 */
final class ShapeFlattener
{
    private const float ELLIPSE_KAPPA = 0.5522847498;
    private const int SEGMENTS_PER_CURVE = 24;

    /**
     * @return list<list<array{x: float, y: float}>>
     */
    public static function toRings(SvgShape $shape, SvgMatrix $deviceMatrix): array
    {
        $commands = self::commandsFor($shape);

        /** @var list<list<array{x: float, y: float}>> $rings */
        $rings = [];
        /** @var list<array{x: float, y: float}> $current */
        $current = [];
        $cx = 0.0;
        $cy = 0.0;
        $startX = 0.0;
        $startY = 0.0;

        $push = static function (float $x, float $y) use (&$current): void {
            /** @var list<array{x: float, y: float}> $current */
            $current[] = ['x' => $x, 'y' => $y];
        };

        $flush = static function () use (&$rings, &$current): void {
            /** @var list<list<array{x: float, y: float}>> $rings */
            /** @var list<array{x: float, y: float}> $current */
            if (count($current) >= 2) {
                $rings[] = $current;
            }
            $current = [];
        };

        foreach ($commands as $cmd) {
            if ($cmd instanceof MoveTo) {
                $flush();
                $push($cmd->x, $cmd->y);
                $cx = $startX = $cmd->x;
                $cy = $startY = $cmd->y;
            } elseif ($cmd instanceof LineTo) {
                $push($cmd->x, $cmd->y);
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof CubicBezier) {
                self::flattenCubic($cx, $cy, $cmd->c1x, $cmd->c1y, $cmd->c2x, $cmd->c2y, $cmd->x, $cmd->y, $push);
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof QuadraticBezier) {
                $c1x = $cx + 2.0 / 3.0 * ($cmd->cx - $cx);
                $c1y = $cy + 2.0 / 3.0 * ($cmd->cy - $cy);
                $c2x = $cmd->x + 2.0 / 3.0 * ($cmd->cx - $cmd->x);
                $c2y = $cmd->y + 2.0 / 3.0 * ($cmd->cy - $cmd->y);
                self::flattenCubic($cx, $cy, $c1x, $c1y, $c2x, $c2y, $cmd->x, $cmd->y, $push);
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof Arc) {
                $px = $cx;
                $py = $cy;
                foreach (ArcToBezier::approximate($cx, $cy, $cmd->rx, $cmd->ry, $cmd->xAxisRotationDeg, $cmd->largeArc, $cmd->sweep, $cmd->x, $cmd->y) as $seg) {
                    self::flattenCubic($px, $py, $seg[0], $seg[1], $seg[2], $seg[3], $seg[4], $seg[5], $push);
                    $px = $seg[4];
                    $py = $seg[5];
                }
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof ClosePath) {
                $push($startX, $startY);
                $flush();
                $cx = $startX;
                $cy = $startY;
            }
        }

        $flush();

        return self::transform($rings, $deviceMatrix);
    }

    /**
     * @return list<SvgPathCommand>
     */
    private static function commandsFor(SvgShape $shape): array
    {
        return match (true) {
            $shape instanceof SvgRect     => self::rectCommands($shape),
            $shape instanceof SvgCircle   => self::ellipseCommands($shape->cx, $shape->cy, $shape->r, $shape->r),
            $shape instanceof SvgEllipse  => self::ellipseCommands($shape->cx, $shape->cy, $shape->rx, $shape->ry),
            $shape instanceof SvgLine     => [new MoveTo($shape->x1, $shape->y1), new LineTo($shape->x2, $shape->y2)],
            $shape instanceof SvgPolygon  => self::polyCommands($shape->points, closed: true),
            $shape instanceof SvgPolyline => self::polyCommands($shape->points, closed: false),
            $shape instanceof SvgPath     => $shape->commands,
            default                       => [],
        };
    }

    /**
     * @return list<SvgPathCommand>
     */
    private static function rectCommands(SvgRect $r): array
    {
        $x = $r->x;
        $y = $r->y;
        $w = $r->width;
        $h = $r->height;

        if (!$r->hasRoundedCorners()) {
            return [
                new MoveTo($x, $y),
                new LineTo($x + $w, $y),
                new LineTo($x + $w, $y + $h),
                new LineTo($x, $y + $h),
                new ClosePath(),
            ];
        }

        // Clamp radii per SVG spec, mirroring Renderer::emitRect.
        $rx = min($r->rx > 0.0 ? $r->rx : $r->ry, $w / 2.0);
        $ry = min($r->ry > 0.0 ? $r->ry : $r->rx, $h / 2.0);

        $commands = [new MoveTo($x + $w - $rx, $y)];
        self::appendArc($commands, $x + $w - $rx, $y, $rx, $ry, $x + $w, $y + $ry);
        $commands[] = new LineTo($x + $w, $y + $h - $ry);
        self::appendArc($commands, $x + $w, $y + $h - $ry, $rx, $ry, $x + $w - $rx, $y + $h);
        $commands[] = new LineTo($x + $rx, $y + $h);
        self::appendArc($commands, $x + $rx, $y + $h, $rx, $ry, $x, $y + $h - $ry);
        $commands[] = new LineTo($x, $y + $ry);
        self::appendArc($commands, $x, $y + $ry, $rx, $ry, $x + $rx, $y);
        $commands[] = new ClosePath();

        return $commands;
    }

    /**
     * @param list<SvgPathCommand> $commands
     */
    private static function appendArc(array &$commands, float $sx, float $sy, float $rx, float $ry, float $ex, float $ey): void
    {
        foreach (ArcToBezier::approximate($sx, $sy, $rx, $ry, 0.0, false, true, $ex, $ey) as [$c1x, $c1y, $c2x, $c2y, $px, $py]) {
            $commands[] = new CubicBezier($c1x, $c1y, $c2x, $c2y, $px, $py);
        }
    }

    /**
     * Four-cubic Bezier-kappa approximation of an ellipse, matching
     * Renderer::emitEllipsoid.
     *
     * @return list<SvgPathCommand>
     */
    private static function ellipseCommands(float $cx, float $cy, float $rx, float $ry): array
    {
        $kx = $rx * self::ELLIPSE_KAPPA;
        $ky = $ry * self::ELLIPSE_KAPPA;

        return [
            new MoveTo($cx + $rx, $cy),
            new CubicBezier($cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry),
            new CubicBezier($cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy),
            new CubicBezier($cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry),
            new CubicBezier($cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy),
            new ClosePath(),
        ];
    }

    /**
     * @param list<array{0: float, 1: float}> $points
     * @return list<SvgPathCommand>
     */
    private static function polyCommands(array $points, bool $closed): array
    {
        if ($points === []) {
            return [];
        }
        $commands = [new MoveTo($points[0][0], $points[0][1])];
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $commands[] = new LineTo($points[$i][0], $points[$i][1]);
        }
        if ($closed) {
            $commands[] = new ClosePath();
        }

        return $commands;
    }

    /**
     * @param list<list<array{x: float, y: float}>> $rings
     * @return list<list<array{x: float, y: float}>>
     */
    private static function transform(array $rings, SvgMatrix $deviceMatrix): array
    {
        /** @var list<list<array{x: float, y: float}>> $out */
        $out = [];
        foreach ($rings as $ring) {
            /** @var list<array{x: float, y: float}> $mapped */
            $mapped = [];
            foreach ($ring as $p) {
                [$tx, $ty] = $deviceMatrix->apply($p['x'], $p['y']);
                $mapped[] = ['x' => $tx, 'y' => $ty];
            }
            $out[] = $mapped;
        }

        return $out;
    }

    /**
     * @param callable(float, float): void $push
     */
    private static function flattenCubic(
        float $x0, float $y0, float $x1, float $y1, float $x2, float $y2, float $x3, float $y3,
        callable $push,
    ): void {
        for ($s = 1; $s <= self::SEGMENTS_PER_CURVE; $s++) {
            $t = $s / self::SEGMENTS_PER_CURVE;
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
