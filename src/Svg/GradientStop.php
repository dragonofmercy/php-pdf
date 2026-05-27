<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/** @internal */
final readonly class GradientStop
{
    public function __construct(
        public float $offset,
        public SvgColor $color,
        public float $opacity,
    ) {}
}
