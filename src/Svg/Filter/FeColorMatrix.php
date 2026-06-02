<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg\Filter;

/** @internal */
final readonly class FeColorMatrix implements FilterPrimitive
{
    /**
     * @param list<float> $values
     */
    public function __construct(
        public ?string $in,
        public ?string $result,
        public ColorMatrixType $type,
        public array $values,
        public ?Subregion $subregion,
    ) {}
}
