<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\PdfA;

/**
 * PDF/A conformance level: ISO 19005 part (2 or 3) and conformance letter
 * (B = basic, U = Unicode). A-3 differs from A-2 only in permitting arbitrary
 * embedded files (consumed in Phase 2).
 */
enum PdfALevel
{
    case A2B;
    case A2U;
    case A3B;
    case A3U;

    public function part(): int
    {
        return match ($this) {
            self::A2B, self::A2U => 2,
            self::A3B, self::A3U => 3,
        };
    }

    public function conformance(): string
    {
        return match ($this) {
            self::A2B, self::A3B => 'B',
            self::A2U, self::A3U => 'U',
        };
    }

    public function allowsEmbeddedFiles(): bool
    {
        return $this->part() === 3;
    }

    public function requiresUnicode(): bool
    {
        return $this->conformance() === 'U';
    }
}
