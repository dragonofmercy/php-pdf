<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\PathCommand;

use DragonOfMercy\PhpPdf\Svg\SvgPathCommand;

final readonly class LineTo implements SvgPathCommand
{
    public function __construct(public float $x, public float $y) {}
}
