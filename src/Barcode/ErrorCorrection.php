<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

/**
 * QR Code error correction level (ISO/IEC 18004 §8.5).
 * The recovery percentage is approximate Reed-Solomon overhead allocated.
 */
enum ErrorCorrection: string
{
    case L = 'L'; // ~7%
    case M = 'M'; // ~15%
    case Q = 'Q'; // ~25%
    case H = 'H'; // ~30%

    /**
     * The 2-bit format identifier per ISO 18004 Table 12.
     * @internal
     */
    public function formatBits(): int
    {
        return match ($this) {
            self::L => 0b01,
            self::M => 0b00,
            self::Q => 0b11,
            self::H => 0b10,
        };
    }

    /** @internal index 0..3 used for ISO Table lookups (L=0, M=1, Q=2, H=3). */
    public function ordinal(): int
    {
        return match ($this) {
            self::L => 0,
            self::M => 1,
            self::Q => 2,
            self::H => 3,
        };
    }
}
