<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\DataMatrix;

/**
 * DataMatrix ECC200 high-level encoder mode (ISO/IEC 16022 Table 6).
 *
 * Only the four modes in scope for this implementation are listed.
 * X12 and EDIFACT are intentionally out of scope (see design spec).
 *
 * @internal
 */
enum DataMatrixMode: string
{
    case ASCII   = 'ASCII';
    case C40     = 'C40';
    case TEXT    = 'TEXT';
    case BASE256 = 'BASE256';
}
