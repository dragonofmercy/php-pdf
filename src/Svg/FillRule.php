<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

enum FillRule: string
{
    case NONZERO = 'nonzero';
    case EVENODD = 'evenodd';
}
