<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg\Filter;

/** @internal */
final readonly class FeMerge implements FilterPrimitive
{
    /**
     * @param list<?string> $inputs
     */
    public function __construct(
        public ?string $result,
        public array $inputs,
        public ?Subregion $subregion,
    ) {}
}
