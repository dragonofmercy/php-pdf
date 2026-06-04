<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

/**
 * Horizontal alignment of text inside a cell.
 */
enum TextAlign
{
    case LEFT;
    case CENTER;
    case RIGHT;
    case JUSTIFY;
}
