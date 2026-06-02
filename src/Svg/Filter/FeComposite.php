<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg\Filter;

/** @internal */
final readonly class FeComposite implements FilterPrimitive
{
    public function __construct(
        public ?string $in,
        public ?string $in2,
        public ?string $result,
        public CompositeOperator $operator,
        public float $k1,
        public float $k2,
        public float $k3,
        public float $k4,
        public ?Subregion $subregion,
    ) {}
}
