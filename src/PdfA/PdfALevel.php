<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\PdfA;

/**
 * PDF/A conformance level: ISO 19005 part (2 or 3) and conformance letter
 * (B = basic, U = Unicode, A = accessible). A-3 differs from A-2 only in
 * permitting arbitrary embedded files. Level A additionally requires a tagged
 * logical structure tree on top of the Unicode requirements.
 */
enum PdfALevel
{
    case A2B;
    case A2U;
    case A2A;
    case A3B;
    case A3U;
    case A3A;

    public function part(): int
    {
        return match ($this) {
            self::A2B, self::A2U, self::A2A => 2,
            self::A3B, self::A3U, self::A3A => 3,
        };
    }

    public function conformance(): string
    {
        return match ($this) {
            self::A2B, self::A3B => 'B',
            self::A2U, self::A3U => 'U',
            self::A2A, self::A3A => 'A',
        };
    }

    public function allowsEmbeddedFiles(): bool
    {
        return $this->part() === 3;
    }

    public function requiresUnicode(): bool
    {
        return $this->conformance() !== 'B';
    }

    public function requiresTagging(): bool
    {
        return $this->conformance() === 'A';
    }
}
