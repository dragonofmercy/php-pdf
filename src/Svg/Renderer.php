<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Font\StandardFontEngine;
use DragonOfMercy\PhpPdf\Font\WinAnsiEncoder;
use DragonOfMercy\PhpPdf\Image\SvgMetadata;
use DragonOfMercy\PhpPdf\Page\Operators;
use DragonOfMercy\PhpPdf\Svg\PathCommand\ClosePath;
use DragonOfMercy\PhpPdf\Svg\PathCommand\LineTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\MoveTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\Arc;
use DragonOfMercy\PhpPdf\Svg\PathCommand\CubicBezier;
use DragonOfMercy\PhpPdf\Svg\PathCommand\QuadraticBezier;
use DragonOfMercy\PhpPdf\Svg\BoundingBox;
use DragonOfMercy\PhpPdf\Svg\ClipPathUnits;
use DragonOfMercy\PhpPdf\Svg\FillRule;
use DragonOfMercy\PhpPdf\Svg\SvgClip;
use DragonOfMercy\PhpPdf\Svg\SvgClipped;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgShape;

/**
 * Translates an SvgMetadata tree into a PDF content-stream byte string.
 *
 * @internal
 */
final class Renderer
{
    private FontRegistry $fontRegistry;
    private MetricsRegistry $metricsRegistry;

    /** @var array<string, true> short names of fonts used by text in this render */
    private array $usedFonts = [];

    /** @var array<string, StandardFontEngine> engine cache keyed by pdfName */
    private array $engines = [];

    public function __construct()
    {
        $this->metricsRegistry = new MetricsRegistry();
    }

    /**
     * @return array{bytes: string, extGStates: array<string, array{ca: float, CA: float}>, patterns: array<string, string>, fonts: list<string>}
     */
    public function render(SvgMetadata $svg, ?FontRegistry $fontRegistry = null): array
    {
        $this->fontRegistry = $fontRegistry ?? new FontRegistry();
        $this->usedFonts = [];
        $out = '';
        $registry = new ExtGStateRegistry();
        $patterns = new PatternRegistry();
        $prologue = self::viewBoxToUnitMatrix($svg->viewBox);
        if (!$prologue->isIdentity()) {
            $out .= "q\n" . self::cmFromMatrix($prologue) . "\n";
        }

        foreach ($svg->root->children as $child) {
            $out .= $this->renderNode($child, $registry, $patterns, $prologue);
        }

        if (!$prologue->isIdentity()) {
            $out .= "Q\n";
        }

        return [
            'bytes' => $out,
            'extGStates' => $registry->entries(),
            'patterns' => $patterns->entries(),
            'fonts' => array_keys($this->usedFonts),
        ];
    }

    /**
     * Maps the viewBox onto the Form's unit square, filling both axes and
     * flipping the Y axis.
     *
     * The page places this Form's unit square at the image's effective width
     * and height, which already carry the viewBox aspect ratio, so filling
     * both axes here keeps the net (prologue then placement) transform uniform
     * and undistorted. The Y flip is required because the page places every
     * image XObject with a vertical-flip matrix (designed for top-row-first
     * rasters); without it the SVG's top-down content would render upside down.
     *
     * The root preserveAspectRatio (meet / slice / align) is intentionally not
     * applied here: it can only be resolved against the placement viewport,
     * which lives at the Page::image layer, not in this Form-local prologue.
     */
    public static function viewBoxToUnitMatrix(ViewBox $vb): SvgMatrix
    {
        $sx = 1.0 / $vb->width;
        $sy = 1.0 / $vb->height;
        return SvgMatrix::translate(-$vb->x * $sx, 1.0 + $vb->y * $sy)
            ->compose(SvgMatrix::scale($sx, -$sy));
    }

    private function renderNode(SvgNode $node, ExtGStateRegistry $registry, PatternRegistry $patterns, SvgMatrix $ctm): string
    {
        if ($node instanceof SvgGroup) {
            return $this->renderGroup($node, $registry, $patterns, $ctm);
        }
        if ($node instanceof SvgShape) {
            return $this->renderShape($node, $registry, $patterns, $ctm);
        }
        if ($node instanceof SvgImage) {
            return $this->renderImage($node, $registry);
        }
        if ($node instanceof SvgText) {
            return $this->renderText($node, $registry);
        }
        if ($node instanceof SvgClipped) {
            return $this->renderClipped($node, $registry, $patterns, $ctm);
        }
        return '';
    }

    private function renderGroup(SvgGroup $group, ExtGStateRegistry $registry, PatternRegistry $patterns, SvgMatrix $ctm): string
    {
        if ($group->children === []) {
            return '';
        }
        $childCtm = $group->transform !== null ? $ctm->compose($group->transform) : $ctm;
        $body = '';
        foreach ($group->children as $child) {
            $body .= $this->renderNode($child, $registry, $patterns, $childCtm);
        }
        if ($body === '') {
            return '';
        }
        $cm = ($group->transform !== null && !$group->transform->isIdentity())
            ? self::cmFromMatrix($group->transform) . "\n"
            : '';
        return "q\n" . $cm . $body . "Q\n";
    }

    private function renderShape(SvgShape $shape, ExtGStateRegistry $registry, PatternRegistry $patterns, SvgMatrix $ctm): string
    {
        $geom = $this->emitGeometry($shape);
        if ($geom === '') {
            return '';
        }
        $tf = $shape->transform();
        $shapeCtm = $tf !== null ? $ctm->compose($tf) : $ctm;
        $paint = $shape->paint();
        $stateOps = $this->emitPaintState($paint, $registry, $patterns, $shape, $shapeCtm);
        $terminator = $this->paintTerminator($paint);
        $cmLine = ($tf !== null && !$tf->isIdentity()) ? self::cmFromMatrix($tf) . "\n" : '';
        return "q\n" . $cmLine . $stateOps . $geom . $terminator . "\nQ\n";
    }

    private function emitPaintState(SvgPaint $paint, ExtGStateRegistry $registry, PatternRegistry $patterns, SvgShape $shape, SvgMatrix $shapeCtm): string
    {
        $out = '';
        $fillOpacity = $paint->effectiveFillOpacity();
        $strokeOpacity = $paint->effectiveStrokeOpacity();

        if ($paint->fill instanceof SvgColor) {
            $out .= sprintf("%s %s %s rg\n", self::fmt($paint->fill->r), self::fmt($paint->fill->g), self::fmt($paint->fill->b));
        } elseif ($paint->fill instanceof SvgGradient) {
            $resolved = $this->paintGradient($paint->fill, $shape, $shapeCtm, $patterns, $fillOpacity, false);
            $out .= $resolved['ops'];
            $fillOpacity = $resolved['opacity'];
        }

        if ($paint->stroke instanceof SvgColor) {
            $out .= sprintf("%s %s %s RG\n", self::fmt($paint->stroke->r), self::fmt($paint->stroke->g), self::fmt($paint->stroke->b));
        } elseif ($paint->stroke instanceof SvgGradient) {
            $resolved = $this->paintGradient($paint->stroke, $shape, $shapeCtm, $patterns, $strokeOpacity, true);
            $out .= $resolved['ops'];
            $strokeOpacity = $resolved['opacity'];
        }

        if ($paint->stroke !== null) {
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

        $name = $registry->nameFor($fillOpacity, $strokeOpacity);
        if ($name !== '') {
            $out .= '/' . $name . " gs\n";
        }
        return $out;
    }

    /**
     * Registers the gradient pattern and returns the color-space ops plus the
     * effective opacity (folding the gradient's uniform opacity in).
     *
     * @return array{ops: string, opacity: float}
     */
    private function paintGradient(SvgGradient $gradient, SvgShape $shape, SvgMatrix $shapeCtm, PatternRegistry $patterns, float $baseOpacity, bool $isStroke): array
    {
        $matrix = $shapeCtm;
        if ($gradient->units() === GradientUnits::OBJECT_BOUNDING_BOX) {
            $bbox = BoundingBox::of($shape);
            if ($bbox->isDegenerate()) {
                $c = $gradient->stops()[0]->color;
                $op = $isStroke ? "%s %s %s RG\n" : "%s %s %s rg\n";
                return ['ops' => sprintf($op, self::fmt($c->r), self::fmt($c->g), self::fmt($c->b)), 'opacity' => $baseOpacity];
            }
            $matrix = $matrix
                ->compose(SvgMatrix::translate($bbox->x, $bbox->y))
                ->compose(SvgMatrix::scale($bbox->width, $bbox->height));
        }
        $gt = $gradient->transform();
        if ($gt !== null) {
            $matrix = $matrix->compose($gt);
        }
        $dict = ShadingBuilder::patternDict($gradient, $matrix);
        $name = $patterns->nameFor($dict);
        $ops = $isStroke ? "/Pattern CS\n/$name SCN\n" : "/Pattern cs\n/$name scn\n";
        return ['ops' => $ops, 'opacity' => $baseOpacity * $gradient->uniformOpacity()];
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
        $out .= sprintf("%s %s m\n", self::fmt($x + $w - $rx), self::fmt($y));
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

    private function renderClipped(SvgClipped $node, ExtGStateRegistry $registry, PatternRegistry $patterns, SvgMatrix $ctm): string
    {
        $clip = $node->clip;
        $geometry = '';
        foreach ($clip->nodes as $clipNode) {
            $geometry .= $this->emitClipGeometry($clipNode);
        }
        // Empty clipPath (or only non-contributing content) clips everything
        // away: the child is not rendered.
        if (trim($geometry) === '') {
            return '';
        }

        $clipCtm = $ctm;
        $prefix = '';
        if ($clip->units === ClipPathUnits::OBJECT_BOUNDING_BOX) {
            $bbox = BoundingBox::ofNode($node->child);
            if ($bbox->isDegenerate()) {
                return '';
            }
            $bboxMatrix = SvgMatrix::translate($bbox->x, $bbox->y)->compose(SvgMatrix::scale($bbox->width, $bbox->height));
            $prefix .= self::cmFromMatrix($bboxMatrix) . "\n";
            $clipCtm = $clipCtm->compose($bboxMatrix);
        }
        if ($clip->transform !== null && !$clip->transform->isIdentity()) {
            $prefix .= self::cmFromMatrix($clip->transform) . "\n";
            $clipCtm = $clipCtm->compose($clip->transform);
        }

        $terminator = $clip->clipRule === FillRule::EVENODD ? "W* n\n" : "W n\n";
        $child = $this->renderNode($node->child, $registry, $patterns, $clipCtm);
        if ($child === '') {
            return '';
        }
        return "q\n" . $prefix . $geometry . $terminator . $child . "Q\n";
    }

    /**
     * Emits path-construction operators only (no paint, no terminator) for a
     * clip child. Shapes contribute their geometry; groups recurse under their
     * transform; per-shape transforms are applied via q/cm/Q. The PDF current
     * path survives q/Q, so subpaths accumulate into one clip path.
     */
    private function emitClipGeometry(SvgNode $node): string
    {
        if ($node instanceof SvgShape) {
            $geom = $this->emitGeometry($node);
            if ($geom === '') {
                return '';
            }
            $tf = $node->transform();
            if ($tf !== null && !$tf->isIdentity()) {
                return "q\n" . self::cmFromMatrix($tf) . "\n" . $geom . "Q\n";
            }
            return $geom;
        }
        if ($node instanceof SvgGroup) {
            $body = '';
            foreach ($node->children as $child) {
                $body .= $this->emitClipGeometry($child);
            }
            if ($body === '') {
                return '';
            }
            if ($node->transform !== null && !$node->transform->isIdentity()) {
                return "q\n" . self::cmFromMatrix($node->transform) . "\n" . $body . "Q\n";
            }
            return $body;
        }
        if ($node instanceof SvgClipped) {
            return $this->emitClipGeometry($node->child);
        }
        // Images and text contribute no clip silhouette.
        return '';
    }

    private function renderImage(SvgImage $img, ExtGStateRegistry $registry): string
    {
        [$fx, $fy, $fw, $fh] = self::fittedRect(
            $img->x, $img->y, $img->width, $img->height,
            $img->intrinsicWidth, $img->intrinsicHeight, $img->aspectRatio,
        );

        $out = "q\n";
        if ($img->transform !== null && !$img->transform->isIdentity()) {
            $out .= self::cmFromMatrix($img->transform) . "\n";
        }
        $name = $registry->nameFor($img->opacity, $img->opacity);
        if ($name !== '') {
            $out .= '/' . $name . " gs\n";
        }
        if ($img->aspectRatio->align !== Align::NONE && $img->aspectRatio->meetOrSlice === MeetOrSlice::SLICE) {
            $out .= sprintf("%s %s %s %s re\nW n\n", self::fmt($img->x), self::fmt($img->y), self::fmt($img->width), self::fmt($img->height));
        }
        $out .= sprintf("%s 0 0 %s %s %s cm\n", self::fmt($fw), self::fmt(-$fh), self::fmt($fx), self::fmt($fy + $fh));
        $out .= '/Im' . $img->imageIndex . " Do\n";
        $out .= "Q\n";
        return $out;
    }

    private function renderText(SvgText $text, ExtGStateRegistry $registry): string
    {
        if ($text->spans === []) {
            return '';
        }

        $placed = $this->layoutSpans($text->spans);
        $body = '';
        foreach ($placed as [$span, $px, $py]) {
            $body .= $this->emitSpan($span, $px, $py, $registry);
        }
        if ($body === '') {
            return '';
        }

        $out = "q\n";
        if ($text->transform !== null && !$text->transform->isIdentity()) {
            $out .= self::cmFromMatrix($text->transform) . "\n";
        }
        $out .= "BT\n" . $body . "ET\n" . "Q\n";
        return $out;
    }

    /**
     * Walks spans into absolute (x, y) baseline positions, applying the chunk
     * anchor model: a chunk is a maximal run of spans where only the first
     * carries an absolute x. text-anchor shifts each chunk's start by 0, -w/2,
     * or -w of the chunk's total advance.
     *
     * @param list<SvgTextSpan> $spans
     * @return list<array{0: SvgTextSpan, 1: float, 2: float}>
     */
    private function layoutSpans(array $spans): array
    {
        $placed = [];
        $penX = 0.0;
        $penY = 0.0;
        $n = count($spans);
        $i = 0;
        while ($i < $n) {
            if ($spans[$i]->x !== null) {
                $penX = $spans[$i]->x;
            }
            if ($spans[$i]->y !== null) {
                $penY = $spans[$i]->y;
            }
            $originX = $penX;
            $chunk = [];
            $j = $i;
            while ($j < $n && ($j === $i || $spans[$j]->x === null)) {
                $span = $spans[$j];
                if ($span->y !== null) {
                    $penY = $span->y;
                }
                $penX += $span->dx;
                $penY += $span->dy;
                $width = $this->engineFor($span->font)->measure($span->text, $span->fontSize);
                $chunk[] = [$span, $penX, $penY];
                $penX += $width;
                $j++;
            }
            $chunkWidth = $penX - $originX;
            $offset = match ($spans[$i]->anchor) {
                TextAnchor::MIDDLE => -$chunkWidth / 2.0,
                TextAnchor::END => -$chunkWidth,
                TextAnchor::START => 0.0,
            };
            foreach ($chunk as [$span, $sx, $sy]) {
                $placed[] = [$span, $sx + $offset, $sy];
            }
            $i = $j;
        }
        return $placed;
    }

    private function emitSpan(SvgTextSpan $span, float $px, float $py, ExtGStateRegistry $registry): string
    {
        $hasFill = $span->fill !== null;
        $hasStroke = $span->stroke !== null;
        if (!$hasFill && !$hasStroke) {
            return '';
        }
        $bytes = WinAnsiEncoder::encode($span->text);
        if ($bytes === '') {
            return '';
        }

        $shortName = $this->fontRegistry->shortName($span->font);
        $this->usedFonts[$shortName] = true;

        $out = sprintf("/%s %s Tf\n", $shortName, self::fmt($span->fontSize));

        $gs = $registry->nameFor($span->fillOpacity, $span->strokeOpacity);
        if ($gs !== '') {
            $out .= '/' . $gs . " gs\n";
        }

        if ($hasFill) {
            $out .= sprintf("%s %s %s rg\n", self::fmt($span->fill->r), self::fmt($span->fill->g), self::fmt($span->fill->b));
        }
        if ($hasStroke) {
            $out .= sprintf("%s %s %s RG\n", self::fmt($span->stroke->r), self::fmt($span->stroke->g), self::fmt($span->stroke->b));
            $out .= sprintf("%s w\n", self::fmt($span->strokeWidth));
        }

        $mode = ($hasFill && $hasStroke) ? 2 : ($hasStroke ? 1 : 0);
        $out .= $mode . " Tr\n";
        $out .= sprintf("1 0 0 -1 %s %s Tm\n", self::fmt($px), self::fmt($py));
        $out .= Operators::showText($bytes);
        return $out;
    }

    private function engineFor(Font $font): StandardFontEngine
    {
        $key = $font->pdfName();
        return $this->engines[$key] ??= new StandardFontEngine($font, $this->metricsRegistry->metricsFor($font));
    }

    /**
     * Fits an intrinsic raster size into a viewport rect per preserveAspectRatio.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} fx, fy, fw, fh
     */
    private static function fittedRect(
        float $vx, float $vy, float $vw, float $vh,
        int $iw, int $ih, PreserveAspectRatio $ar,
    ): array {
        if ($ar->align === Align::NONE || $iw <= 0 || $ih <= 0) {
            return [$vx, $vy, $vw, $vh];
        }
        $sx = $vw / $iw;
        $sy = $vh / $ih;
        $s = $ar->meetOrSlice === MeetOrSlice::MEET ? min($sx, $sy) : max($sx, $sy);
        $fw = $iw * $s;
        $fh = $ih * $s;
        $dx = match ($ar->align) {
            Align::X_MIN_Y_MIN, Align::X_MIN_Y_MID, Align::X_MIN_Y_MAX => 0.0,
            Align::X_MID_Y_MIN, Align::X_MID_Y_MID, Align::X_MID_Y_MAX => ($vw - $fw) / 2.0,
            default => $vw - $fw,
        };
        $dy = match ($ar->align) {
            Align::X_MIN_Y_MIN, Align::X_MID_Y_MIN, Align::X_MAX_Y_MIN => 0.0,
            Align::X_MIN_Y_MID, Align::X_MID_Y_MID, Align::X_MAX_Y_MID => ($vh - $fh) / 2.0,
            default => $vh - $fh,
        };
        return [$vx + $dx, $vy + $dy, $fw, $fh];
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
        return Format::num($v);
    }
}
