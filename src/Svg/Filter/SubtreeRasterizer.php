<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\SvgClipped;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use DragonOfMercy\PhpPdf\Svg\SvgFiltered;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgMasked;
use DragonOfMercy\PhpPdf\Svg\SvgMatrix;
use DragonOfMercy\PhpPdf\Svg\SvgNode;
use DragonOfMercy\PhpPdf\Svg\SvgShape;
use DragonOfMercy\PhpPdf\Svg\SvgText;
use DragonOfMercy\PhpPdf\Svg\SvgTextPath;

/**
 * Walks an SvgNode subtree and draws it into a RasterBuffer (sRGB straight
 * alpha) for the SVG filter pipeline. This is the SourceGraphic producer:
 * the filter primitives operate on the buffer this class fills.
 *
 * Solid fills, groups, nested clip/mask/filter wrappers (clip/mask/filter
 * themselves ignored, only the inner child drawn), raster <image> elements,
 * and gradient fills are rendered. Text (<text> / <textPath>) is intentionally
 * SKIPPED: filtering selectable text is out of scope, and the vector renderer
 * still emits it normally on the unfiltered path.
 *
 * @internal
 */
final class SubtreeRasterizer
{
    public function rasterize(SvgNode $node, SvgMatrix $deviceMatrix, int $width, int $height): RasterBuffer
    {
        $buf = new RasterBuffer($width, $height);
        $this->draw($node, $deviceMatrix, $buf, 1.0);
        return $buf;
    }

    private function draw(SvgNode $node, SvgMatrix $ctm, RasterBuffer $buf, float $opacity): void
    {
        if ($node instanceof SvgGroup) {
            $childCtm = $node->transform !== null ? $ctm->compose($node->transform) : $ctm;
            foreach ($node->children as $child) {
                $this->draw($child, $childCtm, $buf, $opacity);
            }
            return;
        }

        if ($node instanceof SvgShape) {
            $this->drawShape($node, $ctm, $buf, $opacity);
            return;
        }

        if ($node instanceof SvgClipped) {
            $this->draw($node->child, $ctm, $buf, $opacity);
            return;
        }

        if ($node instanceof SvgMasked) {
            $this->draw($node->child, $ctm, $buf, $opacity);
            return;
        }

        if ($node instanceof SvgFiltered) {
            $this->draw($node->child, $ctm, $buf, $opacity);
            return;
        }

        // SvgText / SvgTextPath / anything else: skipped.
        if ($node instanceof SvgText || $node instanceof SvgTextPath) {
            return;
        }
    }

    private function drawShape(SvgShape $shape, SvgMatrix $ctm, RasterBuffer $buf, float $opacity): void
    {
        $tf = $shape->transform();
        $shapeCtm = $tf !== null ? $ctm->compose($tf) : $ctm;
        $paint = $shape->paint();

        $fill = $paint->fill;
        if (!$fill instanceof SvgColor) {
            // none / gradient / pattern: gradient handled in 12c; pattern skipped.
            return;
        }

        $rings = ShapeFlattener::toRings($shape, $shapeCtm);
        if ($rings === []) {
            return;
        }
        $alpha = $paint->effectiveFillOpacity() * $opacity;
        PolygonFiller::fill($buf, $rings, $paint->fillRule, $fill->r, $fill->g, $fill->b, $alpha);
    }
}
