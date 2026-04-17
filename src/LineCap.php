<?php

declare(strict_types=1);

namespace PhpPdf;

/**
 * PDF line cap styles (PDF 1.7 §8.4.3.3). Integer values match the PDF
 * operator parameters for the `J` operator.
 */
enum LineCap: int
{
    case BUTT = 0;
    case ROUND = 1;
    case SQUARE = 2;
}
