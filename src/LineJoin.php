<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

/**
 * PDF line join styles (PDF 1.7 §8.4.3.4). Integer values match the PDF
 * operator parameters for the `j` operator.
 */
enum LineJoin: int
{
    case MITER = 0;
    case ROUND = 1;
    case BEVEL = 2;
}
