<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg\Filter;

/** @internal */
final readonly class FeBlend implements FilterPrimitive
{
    public function __construct(
        public ?string $in,
        public ?string $in2,
        public ?string $result,
        public BlendMode $mode,
        public ?Subregion $subregion,
    ) {}
}
