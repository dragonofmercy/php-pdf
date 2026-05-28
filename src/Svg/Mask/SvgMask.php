<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Mask;

use DragonOfMercy\PhpPdf\Svg\SvgNode;

/**
 * A parsed <mask> definition referenceable via mask="url(#id)" / mask: url(#id).
 * Children are pre-parsed SvgNode instances (shapes, groups, use, image, text).
 * Renderer sub-renders them into a Form XObject used as PDF /SMask.
 *
 * Region (x, y, width, height) interpretation depends on $units. Per spec,
 * units defaults to objectBoundingBox and contentUnits defaults to userSpaceOnUse.
 *
 * @internal
 */
final readonly class SvgMask
{
    /** @param list<SvgNode> $nodes */
    public function __construct(
        public string    $id,
        public MaskUnits $units,
        public MaskUnits $contentUnits,
        public float     $x,
        public float     $y,
        public float     $width,
        public float     $height,
        public array     $nodes,
    ) {}
}
