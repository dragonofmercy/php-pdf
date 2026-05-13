<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\PathCommand;

use DragonOfMercy\PhpPdf\Svg\SvgPathCommand;

final readonly class Arc implements SvgPathCommand
{
    public function __construct(
        public float $rx,
        public float $ry,
        public float $xAxisRotationDeg,
        public bool $largeArc,
        public bool $sweep,
        public float $x,
        public float $y,
    ) {}
}
