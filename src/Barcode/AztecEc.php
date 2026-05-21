<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

/**
 * Aztec Code error correction preset (ISO/IEC 24778).
 *
 * Aztec uses a continuous percentage of codewords for Reed-Solomon
 * redundancy. This enum exposes four named presets covering the practical
 * range. The percentage is the share of the total codeword budget allocated
 * to error correction.
 */
enum AztecEc: string
{
    case LOW = 'LOW';       // ~10%
    case MEDIUM = 'MEDIUM'; // ~23% (default, ISO recommended minimum)
    case HIGH = 'HIGH';     // ~36%
    case MAX = 'MAX';       // ~50%

    /** Share of the total codeword budget reserved for Reed-Solomon error correction. */
    public function redundancyPercent(): int
    {
        return match ($this) {
            self::LOW => 10,
            self::MEDIUM => 23,
            self::HIGH => 36,
            self::MAX => 50,
        };
    }
}
