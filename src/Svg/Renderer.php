<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DragonOfMercy\PhpPdf\Image\SvgMetadata;
use DragonOfMercy\PhpPdf\Svg\PathCommand\ClosePath;
use DragonOfMercy\PhpPdf\Svg\PathCommand\LineTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\MoveTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\Arc;
use DragonOfMercy\PhpPdf\Svg\PathCommand\CubicBezier;
use DragonOfMercy\PhpPdf\Svg\PathCommand\QuadraticBezier;
use DragonOfMercy\PhpPdf\Svg\FillRule;

/**
 * Translates an SvgMetadata tree into a PDF content-stream byte string.
 * Geometry only in Task 9; paint state (rg / RG / w / ...), fill / stroke /
 * fill-and-stroke operator selection, and ExtGState (opacity) come in Tasks
 * 10-11. Arc and Bezier commands are scaffolded here but defer real geometry
 * to Task 10's ArcToBezier helper.
 */
final class Renderer
{
    /**
     * @return array{bytes: string, extGStates: array<string, array{ca: float, CA: float}>}
     */
    public function render(SvgMetadata $svg): array
    {
        $out = '';
        $registry = new ExtGStateRegistry();
        $prologue = self::viewBoxToUnitMatrix($svg->viewBox, $svg->aspectRatio);
        if (!$prologue->isIdentity()) {
            $out .= "q\n" . self::cmFromMatrix($prologue) . "\n";
        }

        foreach ($svg->root->children as $child) {
            $out .= $this->renderNode($child, $registry);
        }

        if (!$prologue->isIdentity()) {
            $out .= "Q\n";
        }

        return ['bytes' => $out, 'extGStates' => $registry->entries()];
    }

    public static function viewBoxToUnitMatrix(ViewBox $vb, PreserveAspectRatio $ar): SvgMatrix
    {
        $sx = 1.0 / $vb->width;
        $sy = 1.0 / $vb->height;
        if ($ar->align === Align::NONE) {
            return SvgMatrix::translate(-$vb->x * $sx, -$vb->y * $sy)->compose(SvgMatrix::scale($sx, $sy));
        }
        $s = $ar->meetOrSlice === MeetOrSlice::MEET ? min($sx, $sy) : max($sx, $sy);
        $vw = $vb->width * $s;
        $vh = $vb->height * $s;
        $dx = match ($ar->align) {
            Align::X_MIN_Y_MIN, Align::X_MIN_Y_MID, Align::X_MIN_Y_MAX => 0.0,
            Align::X_MID_Y_MIN, Align::X_MID_Y_MID, Align::X_MID_Y_MAX => (1.0 - $vw) / 2.0,
            default => 1.0 - $vw, // X_MAX_*
        };
        $dy = match ($ar->align) {
            Align::X_MIN_Y_MIN, Align::X_MID_Y_MIN, Align::X_MAX_Y_MIN => 0.0,
            Align::X_MIN_Y_MID, Align::X_MID_Y_MID, Align::X_MAX_Y_MID => (1.0 - $vh) / 2.0,
            default => 1.0 - $vh, // *_Y_MAX
        };
        return SvgMatrix::translate($dx - $vb->x * $s, $dy - $vb->y * $s)
            ->compose(SvgMatrix::scale($s, $s));
    }

    private function renderNode(SvgNode $node, ExtGStateRegistry $registry): string
    {
        if ($node instanceof SvgGroup) {
            return $this->renderGroup($node, $registry);
        }
        if ($node instanceof SvgShape) {
            return $this->renderShape($node, $registry);
        }
        return '';
    }

    private function renderGroup(SvgGroup $group, ExtGStateRegistry $registry): string
    {
        if ($group->children === []) {
            return '';
        }
        $body = '';
        foreach ($group->children as $child) {
            $body .= $this->renderNode($child, $registry);
        }
        if ($body === '') {
            return '';
        }
        $cm = ($group->transform !== null && !$group->transform->isIdentity())
            ? self::cmFromMatrix($group->transform) . "\n"
            : '';
        return "q\n" . $cm . $body . "Q\n";
    }

    private function renderShape(SvgShape $shape, ExtGStateRegistry $registry): string
    {
        $geom = $this->emitGeometry($shape);
        if ($geom === '') {
            return '';
        }
        $paint = $shape->paint();
        $stateOps = $this->emitPaintState($paint, $registry);
        $terminator = $this->paintTerminator($paint);
        $cmLine = '';
        $tf = $shape->transform();
        if ($tf !== null && !$tf->isIdentity()) {
            $cmLine = self::cmFromMatrix($tf) . "\n";
        }
        return "q\n" . $cmLine . $stateOps . $geom . $terminator . "\nQ\n";
    }

    private function emitPaintState(SvgPaint $paint, ExtGStateRegistry $registry): string
    {
        $out = '';
        if ($paint->fill !== null) {
            $out .= sprintf("%s %s %s rg\n", self::fmt($paint->fill->r), self::fmt($paint->fill->g), self::fmt($paint->fill->b));
        }
        if ($paint->stroke !== null) {
            $out .= sprintf("%s %s %s RG\n", self::fmt($paint->stroke->r), self::fmt($paint->stroke->g), self::fmt($paint->stroke->b));
            $out .= sprintf("%s w\n", self::fmt($paint->strokeWidth));
            $out .= sprintf("%d J\n", $paint->strokeLineCap->toPdfCode());
            $out .= sprintf("%d j\n", $paint->strokeLineJoin->toPdfCode());
            if ($paint->strokeMiterLimit !== 4.0) {
                $out .= sprintf("%s M\n", self::fmt($paint->strokeMiterLimit));
            }
            if ($paint->strokeDashArray !== []) {
                $parts = array_map(static fn (float $v): string => self::fmt($v), $paint->strokeDashArray);
                $out .= sprintf("[%s] %s d\n", implode(' ', $parts), self::fmt($paint->strokeDashOffset));
            }
        }
        $name = $registry->nameFor($paint->effectiveFillOpacity(), $paint->effectiveStrokeOpacity());
        if ($name !== '') {
            $out .= '/' . $name . " gs\n";
        }
        return $out;
    }

    private function paintTerminator(SvgPaint $paint): string
    {
        $hasFill = $paint->fill !== null;
        $hasStroke = $paint->stroke !== null;
        $evenodd = $paint->fillRule === FillRule::EVENODD;
        if ($hasFill && $hasStroke) {
            return $evenodd ? 'B*' : 'B';
        }
        if ($hasFill) {
            return $evenodd ? 'f*' : 'f';
        }
        if ($hasStroke) {
            return 'S';
        }
        return 'n';
    }

    private function emitGeometry(SvgShape $shape): string
    {
        return match (true) {
            $shape instanceof SvgRect     => $this->emitRect($shape),
            $shape instanceof SvgCircle   => $this->emitCircle($shape),
            $shape instanceof SvgEllipse  => $this->emitEllipse($shape),
            $shape instanceof SvgLine     => $this->emitLine($shape),
            $shape instanceof SvgPolygon  => $this->emitPolygon($shape, closed: true),
            $shape instanceof SvgPolyline => $this->emitPolygon($shape, closed: false),
            $shape instanceof SvgPath     => $this->emitPath($shape),
            default                       => '',
        };
    }

    private function emitRect(SvgRect $r): string
    {
        if (!$r->hasRoundedCorners()) {
            return sprintf("%s %s %s %s re\n", self::fmt($r->x), self::fmt($r->y), self::fmt($r->width), self::fmt($r->height));
        }
        // Clamp radii per SVG spec: rx <= width/2, ry <= height/2.
        $rx = min($r->rx > 0.0 ? $r->rx : $r->ry, $r->width / 2.0);
        $ry = min($r->ry > 0.0 ? $r->ry : $r->rx, $r->height / 2.0);
        $x = $r->x;
        $y = $r->y;
        $w = $r->width;
        $h = $r->height;
        $out = '';
        // Start at the top of the right edge, just above the top-right corner.
        $out .= sprintf("%s %s m\n", self::fmt($x + $w - $rx), self::fmt($y));
        // Top edge
        $out .= sprintf("%s %s l\n", self::fmt($x + $w - $rx), self::fmt($y));
        // Top-right corner arc (quarter circle from (x+w-rx, y) to (x+w, y+ry))
        foreach (ArcToBezier::approximate($x + $w - $rx, $y, $rx, $ry, 0.0, false, true, $x + $w, $y + $ry) as [$c1x, $c1y, $c2x, $c2y, $ex, $ey]) {
            $out .= sprintf("%s %s %s %s %s %s c\n", self::fmt($c1x), self::fmt($c1y), self::fmt($c2x), self::fmt($c2y), self::fmt($ex), self::fmt($ey));
        }
        // Right edge
        $out .= sprintf("%s %s l\n", self::fmt($x + $w), self::fmt($y + $h - $ry));
        // Bottom-right corner arc
        foreach (ArcToBezier::approximate($x + $w, $y + $h - $ry, $rx, $ry, 0.0, false, true, $x + $w - $rx, $y + $h) as [$c1x, $c1y, $c2x, $c2y, $ex, $ey]) {
            $out .= sprintf("%s %s %s %s %s %s c\n", self::fmt($c1x), self::fmt($c1y), self::fmt($c2x), self::fmt($c2y), self::fmt($ex), self::fmt($ey));
        }
        // Bottom edge
        $out .= sprintf("%s %s l\n", self::fmt($x + $rx), self::fmt($y + $h));
        // Bottom-left corner arc
        foreach (ArcToBezier::approximate($x + $rx, $y + $h, $rx, $ry, 0.0, false, true, $x, $y + $h - $ry) as [$c1x, $c1y, $c2x, $c2y, $ex, $ey]) {
            $out .= sprintf("%s %s %s %s %s %s c\n", self::fmt($c1x), self::fmt($c1y), self::fmt($c2x), self::fmt($c2y), self::fmt($ex), self::fmt($ey));
        }
        // Left edge
        $out .= sprintf("%s %s l\n", self::fmt($x), self::fmt($y + $ry));
        // Top-left corner arc
        foreach (ArcToBezier::approximate($x, $y + $ry, $rx, $ry, 0.0, false, true, $x + $rx, $y) as [$c1x, $c1y, $c2x, $c2y, $ex, $ey]) {
            $out .= sprintf("%s %s %s %s %s %s c\n", self::fmt($c1x), self::fmt($c1y), self::fmt($c2x), self::fmt($c2y), self::fmt($ex), self::fmt($ey));
        }
        $out .= "h\n";
        return $out;
    }

    private function emitCircle(SvgCircle $c): string
    {
        return $this->emitEllipsoid($c->cx, $c->cy, $c->r, $c->r);
    }

    private function emitEllipse(SvgEllipse $e): string
    {
        return $this->emitEllipsoid($e->cx, $e->cy, $e->rx, $e->ry);
    }

    /**
     * Four-cubic Bezier-kappa approximation of an ellipse, matching the
     * algorithm already used by Page::circle().
     */
    private function emitEllipsoid(float $cx, float $cy, float $rx, float $ry): string
    {
        $k = 0.5522847498;
        $kx = $rx * $k;
        $ky = $ry * $k;
        return sprintf("%s %s m\n", self::fmt($cx + $rx), self::fmt($cy))
            . sprintf("%s %s %s %s %s %s c\n",
                self::fmt($cx + $rx), self::fmt($cy + $ky),
                self::fmt($cx + $kx), self::fmt($cy + $ry),
                self::fmt($cx), self::fmt($cy + $ry))
            . sprintf("%s %s %s %s %s %s c\n",
                self::fmt($cx - $kx), self::fmt($cy + $ry),
                self::fmt($cx - $rx), self::fmt($cy + $ky),
                self::fmt($cx - $rx), self::fmt($cy))
            . sprintf("%s %s %s %s %s %s c\n",
                self::fmt($cx - $rx), self::fmt($cy - $ky),
                self::fmt($cx - $kx), self::fmt($cy - $ry),
                self::fmt($cx), self::fmt($cy - $ry))
            . sprintf("%s %s %s %s %s %s c\n",
                self::fmt($cx + $kx), self::fmt($cy - $ry),
                self::fmt($cx + $rx), self::fmt($cy - $ky),
                self::fmt($cx + $rx), self::fmt($cy))
            . "h\n";
    }

    private function emitLine(SvgLine $l): string
    {
        return sprintf("%s %s m\n", self::fmt($l->x1), self::fmt($l->y1))
            . sprintf("%s %s l\n", self::fmt($l->x2), self::fmt($l->y2));
    }

    private function emitPolygon(SvgPolygon|SvgPolyline $p, bool $closed): string
    {
        if ($p->points === []) {
            return '';
        }
        $out = sprintf("%s %s m\n", self::fmt($p->points[0][0]), self::fmt($p->points[0][1]));
        for ($i = 1, $n = count($p->points); $i < $n; $i++) {
            $out .= sprintf("%s %s l\n", self::fmt($p->points[$i][0]), self::fmt($p->points[$i][1]));
        }
        if ($closed) {
            $out .= "h\n";
        }
        return $out;
    }

    private function emitPath(SvgPath $p): string
    {
        $out = '';
        $cx = 0.0;
        $cy = 0.0;
        foreach ($p->commands as $cmd) {
            if ($cmd instanceof MoveTo) {
                $out .= sprintf("%s %s m\n", self::fmt($cmd->x), self::fmt($cmd->y));
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof LineTo) {
                $out .= sprintf("%s %s l\n", self::fmt($cmd->x), self::fmt($cmd->y));
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof CubicBezier) {
                $out .= sprintf("%s %s %s %s %s %s c\n",
                    self::fmt($cmd->c1x), self::fmt($cmd->c1y),
                    self::fmt($cmd->c2x), self::fmt($cmd->c2y),
                    self::fmt($cmd->x), self::fmt($cmd->y));
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof QuadraticBezier) {
                // Elevate Q to cubic: C1 = current + 2/3*(Q - current), C2 = end + 2/3*(Q - end)
                $c1x = $cx + (2.0 / 3.0) * ($cmd->cx - $cx);
                $c1y = $cy + (2.0 / 3.0) * ($cmd->cy - $cy);
                $c2x = $cmd->x + (2.0 / 3.0) * ($cmd->cx - $cmd->x);
                $c2y = $cmd->y + (2.0 / 3.0) * ($cmd->cy - $cmd->y);
                $out .= sprintf("%s %s %s %s %s %s c\n",
                    self::fmt($c1x), self::fmt($c1y),
                    self::fmt($c2x), self::fmt($c2y),
                    self::fmt($cmd->x), self::fmt($cmd->y));
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof Arc) {
                $beziers = ArcToBezier::approximate(
                    $cx, $cy, $cmd->rx, $cmd->ry, $cmd->xAxisRotationDeg,
                    $cmd->largeArc, $cmd->sweep, $cmd->x, $cmd->y,
                );
                foreach ($beziers as [$c1x, $c1y, $c2x, $c2y, $ex, $ey]) {
                    $out .= sprintf("%s %s %s %s %s %s c\n",
                        self::fmt($c1x), self::fmt($c1y),
                        self::fmt($c2x), self::fmt($c2y),
                        self::fmt($ex), self::fmt($ey));
                }
                $cx = $cmd->x;
                $cy = $cmd->y;
            } elseif ($cmd instanceof ClosePath) {
                $out .= "h\n";
            }
        }
        return $out;
    }

    private static function cmFromMatrix(SvgMatrix $m): string
    {
        return sprintf('%s %s %s %s %s %s cm',
            self::fmt($m->a), self::fmt($m->b),
            self::fmt($m->c), self::fmt($m->d),
            self::fmt($m->e), self::fmt($m->f),
        );
    }

    /**
     * Stable, locale-independent number formatting. Trailing zeros and the
     * trailing decimal point are stripped. Critical for byte-identity goldens.
     */
    private static function fmt(float $v): string
    {
        if ($v == (int) $v && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        $s = number_format($v, 6, '.', '');
        $s = rtrim($s, '0');
        return rtrim($s, '.');
    }
}
