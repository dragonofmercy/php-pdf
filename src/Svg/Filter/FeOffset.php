<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg\Filter;

/** @internal */
final readonly class FeOffset implements FilterPrimitive
{
    public function __construct(
        public ?string $in,
        public ?string $result,
        public float $dx,
        public float $dy,
        public ?Subregion $subregion,
    ) {}
}
