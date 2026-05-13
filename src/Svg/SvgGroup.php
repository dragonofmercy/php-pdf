<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * Container for <g> and the root <svg> element. Carries a local transform
 * (composed into a `cm` operator at render time) and a list of children.
 * Groups themselves never draw -- they only emit q/cm/Q wrappers around
 * their descendants.
 */
final readonly class SvgGroup implements SvgNode
{
    /**
     * @param list<SvgNode> $children
     */
    public function __construct(
        public ?SvgMatrix $transform,
        public array $children,
    ) {}
}
