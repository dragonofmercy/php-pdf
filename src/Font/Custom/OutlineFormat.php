<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

/**
 * Outline format of a parsed sfnt font. TrueType = glyf/loca (3a/3b path,
 * subsettable); Cff = CFF charstrings (3c path, whole-embedded as
 * CIDFontType0 / FontFile3 OpenType).
 *
 * @internal
 */
enum OutlineFormat
{
    case TrueType;
    case Cff;
}
