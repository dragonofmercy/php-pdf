<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * Wraps any node with a clip path. Non-invasive: instead of adding a clip
 * field to every node type, the parser wraps a clipped element in this and the
 * renderer emits the clip before rendering the child.
 *
 * @internal
 */
final readonly class SvgClipped implements SvgNode
{
    public function __construct(
        public SvgClip $clip,
        public SvgNode $child,
    ) {}
}
