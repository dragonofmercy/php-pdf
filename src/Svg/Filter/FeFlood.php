<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\SvgColor;

/** @internal */
final readonly class FeFlood implements FilterPrimitive
{
    public function __construct(
        public ?string $result,
        public SvgColor $floodColor,
        public float $floodOpacity,
        public ?Subregion $subregion,
    ) {}
}
