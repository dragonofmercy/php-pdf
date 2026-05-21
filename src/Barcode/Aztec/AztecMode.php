<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Aztec;

/**
 * Aztec high-level encoder mode (ISO/IEC 24778 Table 3).
 *
 * UPPER, LOWER, MIXED and PUNCT use 5-bit codewords. DIGIT uses 4-bit
 * codewords. BYTE uses 8-bit codewords (entered with a length prefix, exited
 * automatically after the announced byte count).
 *
 * @internal
 */
enum AztecMode: string
{
    case UPPER = 'UPPER';
    case LOWER = 'LOWER';
    case MIXED = 'MIXED';
    case PUNCT = 'PUNCT';
    case DIGIT = 'DIGIT';
    case BYTE  = 'BYTE';

    /** Codeword size in bits for this mode. */
    public function bitsPerCodeword(): int
    {
        return match ($this) {
            self::DIGIT => 4,
            self::BYTE  => 8,
            self::UPPER, self::LOWER, self::MIXED, self::PUNCT => 5,
        };
    }
}
