<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use DragonOfMercy\PhpPdf\Font\FontFamily;

/**
 * Fluent readonly builder for the 12 standard PDF fonts (Helvetica, Times,
 * Courier × 4 variants). Each method returns a new instance.
 */
final readonly class Font
{
    private function __construct(
        private FontFamily $family,
        private bool $bold,
        private bool $italic,
    ) {}

    public static function helvetica(): self
    {
        return new self(FontFamily::HELVETICA, bold: false, italic: false);
    }

    public static function times(): self
    {
        return new self(FontFamily::TIMES, bold: false, italic: false);
    }

    public static function courier(): self
    {
        return new self(FontFamily::COURIER, bold: false, italic: false);
    }

    public function bold(): self
    {
        return new self($this->family, bold: true, italic: $this->italic);
    }

    public function italic(): self
    {
        return new self($this->family, bold: $this->bold, italic: true);
    }

    /**
     * Returns the PDF canonical font name (e.g., "Helvetica-BoldOblique").
     *
     * @internal
     */
    public function pdfName(): string
    {
        return match ($this->family) {
            FontFamily::HELVETICA => $this->composeName('Helvetica', 'Bold', 'Oblique'),
            FontFamily::TIMES => $this->timesName(),
            FontFamily::COURIER => $this->composeName('Courier', 'Bold', 'Oblique'),
        };
    }

    private function composeName(string $base, string $boldSuffix, string $italicSuffix): string
    {
        if (!$this->bold && !$this->italic) {
            return $base;
        }
        if ($this->bold && !$this->italic) {
            return $base . '-' . $boldSuffix;
        }
        if (!$this->bold && $this->italic) {
            return $base . '-' . $italicSuffix;
        }
        return $base . '-' . $boldSuffix . $italicSuffix;
    }

    private function timesName(): string
    {
        if (!$this->bold && !$this->italic) {
            return 'Times-Roman';
        }
        if ($this->bold && !$this->italic) {
            return 'Times-Bold';
        }
        if (!$this->bold && $this->italic) {
            return 'Times-Italic';
        }
        return 'Times-BoldItalic';
    }
}
