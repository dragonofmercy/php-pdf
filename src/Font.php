<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use DragonOfMercy\PhpPdf\Font\FontFamily;
use LogicException;

/**
 * Fluent readonly builder for fonts. Supports the 12 standard PDF fonts
 * (Helvetica, Times, Courier x 4 variants) and custom TTF aliases registered
 * on the Document via registerFontFamily().
 */
final readonly class Font
{
    private function __construct(
        private ?FontFamily $family,
        private ?string $customAlias,
        private bool $bold,
        private bool $italic,
    ) {}

    public static function helvetica(): self
    {
        return new self(FontFamily::HELVETICA, null, bold: false, italic: false);
    }

    public static function times(): self
    {
        return new self(FontFamily::TIMES, null, bold: false, italic: false);
    }

    public static function courier(): self
    {
        return new self(FontFamily::COURIER, null, bold: false, italic: false);
    }

    public static function custom(string $alias): self
    {
        return new self(null, $alias, bold: false, italic: false);
    }

    public function bold(): self
    {
        return new self($this->family, $this->customAlias, bold: true, italic: $this->italic);
    }

    public function italic(): self
    {
        return new self($this->family, $this->customAlias, bold: $this->bold, italic: true);
    }

    public function isCustom(): bool
    {
        return $this->customAlias !== null;
    }

    public function customAlias(): ?string
    {
        return $this->customAlias;
    }

    public function isBold(): bool
    {
        return $this->bold;
    }

    public function isItalic(): bool
    {
        return $this->italic;
    }

    /**
     * Returns the PDF canonical font name (e.g., "Helvetica-BoldOblique").
     * Only valid for standard fonts. Custom fonts must be resolved via
     * FontResolver since their PDF name comes from the parsed TTF.
     *
     * @internal
     */
    public function pdfName(): string
    {
        if ($this->customAlias !== null) {
            throw new LogicException('pdfName() is not supported for custom fonts; resolve via FontResolver');
        }
        return match ($this->family) {
            FontFamily::HELVETICA => $this->composeName('Helvetica', 'Bold', 'Oblique'),
            FontFamily::TIMES => $this->timesName(),
            FontFamily::COURIER => $this->composeName('Courier', 'Bold', 'Oblique'),
            null => throw new LogicException('Font with no family and no custom alias is invalid'),
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
