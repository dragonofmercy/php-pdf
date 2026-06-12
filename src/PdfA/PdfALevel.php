<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\PdfA;

/**
 * PDF/A conformance level: ISO 19005 part (1, 2, 3, or 4) and a conformance
 * letter (B = basic, U = Unicode, A = accessible). Part 1 (PDF 1.4-based) has
 * levels B and A only (no U). Part 3 differs from part 2 only in permitting
 * arbitrary embedded files. Level A additionally requires a tagged logical
 * structure tree. Part 4 (PDF/A-4, PDF 2.0-based) has no conformance letter:
 * Unicode mapping is mandatory and tagging is not required; the A4F flavour
 * additionally permits arbitrary embedded files.
 */
enum PdfALevel
{
    case A1B;
    case A1A;
    case A2B;
    case A2U;
    case A2A;
    case A3B;
    case A3U;
    case A3A;
    case A4;
    case A4F;

    public function part(): int
    {
        return match ($this) {
            self::A1B, self::A1A => 1,
            self::A2B, self::A2U, self::A2A => 2,
            self::A3B, self::A3U, self::A3A => 3,
            self::A4, self::A4F => 4,
        };
    }

    /**
     * Whether this level forbids the /Info trailer dictionary. PDF 2.0 (and thus
     * PDF/A-4) deprecates the document information dictionary, so all metadata
     * must live in the XMP stream instead. Parts 2-3 still emit /Info.
     */
    public function omitsInfoDictionary(): bool
    {
        return match ($this) {
            self::A1B, self::A1A, self::A2B, self::A2U, self::A2A, self::A3B, self::A3U, self::A3A => false,
            self::A4, self::A4F => true,
        };
    }

    /**
     * PDF base version emitted in the file header (PDF/A-4 is PDF 2.0-based).
     */
    public function headerVersion(): string
    {
        return match ($this) {
            self::A1B, self::A1A => '1.4',
            self::A2B, self::A2U, self::A2A, self::A3B, self::A3U, self::A3A => '1.7',
            self::A4, self::A4F => '2.0',
        };
    }

    /**
     * XMP pdfaid:conformance letter (B / U / A / F), or null for the base PDF/A-4
     * level which carries no conformance letter. Parts 2-3 use B/U/A; PDF/A-4f
     * uses F (ISO 19005-4:2020 clause 6.7.3).
     */
    public function xmpConformance(): ?string
    {
        return match ($this) {
            self::A1B, self::A2B, self::A3B => 'B',
            self::A1A, self::A2A, self::A3A => 'A',
            self::A2U, self::A3U => 'U',
            self::A4F => 'F',
            self::A4 => null,
        };
    }

    /**
     * XMP pdfaid:rev year for PDF/A-4 (2020); null for parts 2-3, which use a
     * conformance letter instead.
     */
    public function xmpRev(): ?int
    {
        return match ($this) {
            self::A1B, self::A1A, self::A2B, self::A2U, self::A2A, self::A3B, self::A3U, self::A3A => null,
            self::A4, self::A4F => 2020,
        };
    }

    public function allowsEmbeddedFiles(): bool
    {
        return match ($this) {
            self::A1B, self::A1A, self::A2B, self::A2U, self::A2A, self::A4 => false,
            self::A3B, self::A3U, self::A3A, self::A4F => true,
        };
    }

    /**
     * PDF/A-1 (ISO 19005-1) is PDF 1.4-based and forbids all transparency
     * (alpha, soft masks, blend modes, transparency groups); later parts permit it.
     */
    public function forbidsTransparency(): bool
    {
        return match ($this) {
            self::A1B, self::A1A => true,
            self::A2B, self::A2U, self::A2A, self::A3B, self::A3U, self::A3A, self::A4, self::A4F => false,
        };
    }

    public function requiresUnicode(): bool
    {
        return $this->xmpConformance() !== 'B';
    }

    public function requiresTagging(): bool
    {
        return $this->xmpConformance() === 'A';
    }
}
