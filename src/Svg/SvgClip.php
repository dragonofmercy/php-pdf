<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * A resolved clip path: the geometry that an element's `clip-path` reference
 * points at, ready to emit as a PDF clip. `nodes` are the parsed children of
 * the <clipPath> (shapes, or groups produced by <use>); their union forms the
 * clip region. `clipRule` selects nonzero (W) vs evenodd (W*).
 *
 * @internal
 */
final readonly class SvgClip
{
    /**
     * @param list<SvgNode> $nodes
     */
    public function __construct(
        public ClipPathUnits $units,
        public ?SvgMatrix $transform,
        public array $nodes,
        public FillRule $clipRule,
    ) {}
}
