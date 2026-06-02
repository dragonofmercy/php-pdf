<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg\Filter;

/** @internal */
final readonly class FeGaussianBlur implements FilterPrimitive
{
    public function __construct(
        public ?string $in,
        public ?string $result,
        public float $stdDeviationX,
        public float $stdDeviationY,
        public ?Subregion $subregion,
    ) {}
}
