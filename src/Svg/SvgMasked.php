<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DragonOfMercy\PhpPdf\Svg\Mask\SvgMask;

/**
 * Wraps any node with a soft mask. Non-invasive: instead of adding a mask
 * field to every node type, the parser wraps a masked element in this and the
 * renderer emits the /SMask ExtGState wrapper before rendering the child.
 *
 * Mirrors SvgClipped. When a node carries both mask and clip, the parser
 * nests SvgMasked(SvgClipped(child)) so clipping applies inside the mask
 * (PDF stacks them naturally via nested q/Q).
 *
 * @internal
 */
final readonly class SvgMasked implements SvgNode
{
    public function __construct(
        public SvgMask $mask,
        public SvgNode $child,
    ) {}
}
