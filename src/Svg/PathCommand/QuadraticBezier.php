<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\PathCommand;

use DragonOfMercy\PhpPdf\Svg\SvgPathCommand;

final readonly class QuadraticBezier implements SvgPathCommand
{
    public function __construct(
        public float $cx,
        public float $cy,
        public float $x,
        public float $y,
    ) {}
}
