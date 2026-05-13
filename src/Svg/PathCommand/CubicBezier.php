<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\PathCommand;

use DragonOfMercy\PhpPdf\Svg\SvgPathCommand;

final readonly class CubicBezier implements SvgPathCommand
{
    public function __construct(
        public float $c1x,
        public float $c1y,
        public float $c2x,
        public float $c2y,
        public float $x,
        public float $y,
    ) {}
}
