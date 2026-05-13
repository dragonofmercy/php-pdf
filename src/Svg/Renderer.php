<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DragonOfMercy\PhpPdf\Image\SvgMetadata;
use DragonOfMercy\PhpPdf\Svg\PathCommand\ClosePath;
use DragonOfMercy\PhpPdf\Svg\PathCommand\LineTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\MoveTo;

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
        $prologue = self::viewBoxToUnitMatrix($svg->viewBox, $svg->aspectRatio);
        if (!$prologue->isIdentity()) {
            $out .= "q\n" . self::cmFromMatrix($prologue) . "\n";
        }

        $extGStates = [];
        foreach ($svg->root->children as $child) {
            $out .= $this->renderNode($child, $extGStates);
        }

        if (!$prologue->isIdentity()) {
            $out .= "Q\n";
        }

        return ['bytes' => $out, 'extGStates' => $extGStates];
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

    /**
     * @param array<string, array{ca: float, CA: float}> $extGStates
     */
    private function renderNode(SvgNode $node, array &$extGStates): string
    {
        if ($node instanceof SvgGroup) {
            return $this->renderGroup($node, $extGStates);
        }
        if ($node instanceof SvgShape) {
            return $this->renderShape($node, $extGStates);
        }
        return '';
    }

    /**
     * @param array<string, array{ca: float, CA: float}> $extGStates
     */
    private function renderGroup(SvgGroup $group, array &$extGStates): string
    {
        if ($group->children === []) {
            return '';
        }
        $body = '';
        foreach ($group->children as $child) {
            $body .= $this->renderNode($child, $extGStates);
        }
        if ($body === '') {
            return '';
        }
        $cm = ($group->transform !== null && !$group->transform->isIdentity())
            ? self::cmFromMatrix($group->transform) . "\n"
            : '';
        return "q\n" . $cm . $body . "Q\n";
    }

    /**
     * @param array<string, array{ca: float, CA: float}> $extGStates
     */
    private function renderShape(SvgShape $shape, array &$extGStates): string
    {
        $geom = $this->emitGeometry($shape);
        if ($geom === '') {
            return '';
        }
        // Paint state operators + paint terminator land here in Task 11.
        // For Task 9 we just emit the geometry inside q/Q with no paint.
        $transform = $shape->transform();
        $cm = ($transform !== null && !$transform->isIdentity())
            ? self::cmFromMatrix($transform) . "\n"
            : '';
        return "q\n" . $cm . $geom . "n\n" . "Q\n"; // 'n' = end-path-without-paint, placeholder
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
        if ($r->hasRoundedCorners()) {
            // Task 10 expands rx/ry rects into a path with corner arcs.
            return '';
        }
        return sprintf("%s %s %s %s re\n", self::fmt($r->x), self::fmt($r->y), self::fmt($r->width), self::fmt($r->height));
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
        foreach ($p->commands as $cmd) {
            if ($cmd instanceof MoveTo) {
                $out .= sprintf("%s %s m\n", self::fmt($cmd->x), self::fmt($cmd->y));
            } elseif ($cmd instanceof LineTo) {
                $out .= sprintf("%s %s l\n", self::fmt($cmd->x), self::fmt($cmd->y));
            } elseif ($cmd instanceof ClosePath) {
                $out .= "h\n";
            }
            // CubicBezier / QuadraticBezier / Arc handled in Task 10.
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
