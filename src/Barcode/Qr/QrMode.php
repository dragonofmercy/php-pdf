<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Qr;

/**
 * QR data encoding modes (ISO/IEC 18004 Section 6.4 + Table 2).
 * Each backed value is the human-readable name; the 4-bit mode indicator
 * lives on {@see self::indicator()}.
 *
 * Kanji and ECI modes are not supported in this release.
 *
 * @internal
 */
enum QrMode: string
{
    case Numeric = 'numeric';
    case Alphanumeric = 'alphanumeric';
    case Byte = 'byte';

    /** Returns the 4-bit mode indicator string per ISO 18004 Table 2. */
    public function indicator(): string
    {
        return match ($this) {
            self::Numeric => '0001',
            self::Alphanumeric => '0010',
            self::Byte => '0100',
        };
    }
}
