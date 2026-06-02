<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg;

use DragonOfMercy\PhpPdf\Svg\Filter\SvgFilter;

/**
 * Wraps any node with a filter. Non-invasive: instead of adding a filter
 * field to every node type, the parser wraps a filtered element in this and
 * the renderer rasterizes the child into a pixel buffer, applies the filter
 * pipeline, and embeds the result as an image XObject.
 *
 * Mirrors SvgMasked / SvgClipped. When a node carries both filter and mask,
 * the parser nests SvgFiltered(SvgMasked(child)) so the mask applies inside
 * the filter rasterization pass.
 *
 * @internal
 */
final readonly class SvgFiltered implements SvgNode
{
    public function __construct(
        public SvgFilter $filter,
        public SvgNode $child,
    ) {}
}
