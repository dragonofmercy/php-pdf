<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\SvgColor;

/** @internal */
final readonly class FeDropShadow implements FilterPrimitive
{
    public function __construct(
        public ?string $in,
        public ?string $result,
        public float $dx,
        public float $dy,
        public float $stdDeviationX,
        public float $stdDeviationY,
        public SvgColor $floodColor,
        public float $floodOpacity,
        public ?Subregion $subregion,
    ) {}
}
