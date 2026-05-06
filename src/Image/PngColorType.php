<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

/**
 * PNG IHDR color type values (PNG specification, section 11.2.2).
 *
 * @internal
 */
enum PngColorType: int
{
    case GRAY = 0;
    case RGB = 2;
    case PALETTE = 3;
    case GRAY_ALPHA = 4;
    case RGB_ALPHA = 6;
}
