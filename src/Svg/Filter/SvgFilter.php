<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg\Filter;

/**
 * Represents a parsed SVG <filter> element.
 * Holds the filter region (x, y, width, height in filterUnits coordinates)
 * and an ordered list of filter primitives to apply in sequence.
 *
 * @internal
 */
final readonly class SvgFilter
{
    /**
     * @param list<FilterPrimitive> $primitives
     */
    public function __construct(
        public ?string $id,
        public FilterUnits $filterUnits,
        public FilterUnits $primitiveUnits,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public array $primitives,
    ) {}
}
