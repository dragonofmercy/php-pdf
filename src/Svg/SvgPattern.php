<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * A parsed <pattern> definition referenceable via fill="url(#id)" or
 * stroke="url(#id)". Children are pre-parsed into SvgNode instances
 * (shapes, groups, <use>); <text> and <image> are stripped at parse time
 * (see Parser::parseNode inPattern flag).
 *
 * @internal
 */
final readonly class SvgPattern implements SvgPaintSource
{
    /** @param list<SvgNode> $nodes */
    public function __construct(
        public PatternUnits $units,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public ?SvgMatrix $transform,
        public ?ViewBox $viewBox,
        public array $nodes,
    ) {}
}
