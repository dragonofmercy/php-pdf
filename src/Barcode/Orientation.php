<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

/**
 * Render orientation for 1D barcodes. Vertical rotates the symbol 90 degrees
 * counter-clockwise so the bottom of the horizontal code ends up on the left.
 */
enum Orientation
{
    case Horizontal;
    case Vertical;
}
