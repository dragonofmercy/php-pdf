<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

/**
 * AcroForm widget border style, emitted as the /BS /S name (PDF 32000-1
 * Table 168). Distinct from the cells BorderStyle enum: form fields support
 * Beveled and Inset, which cells do not.
 */
enum FieldBorderStyle
{
    case SOLID;      // /S /S
    case DASHED;     // /S /D (with a /D [3] dash array)
    case BEVELED;    // /S /B
    case INSET;      // /S /I
    case UNDERLINE;  // /S /U
}
