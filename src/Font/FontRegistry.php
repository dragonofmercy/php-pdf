<?php

declare(strict_types=1);

namespace PhpPdf\Font;

use PhpPdf\Font;

/**
 * Ordered registry of fonts used across all pages of a document. Attribute
 * short PDF names (`F1`, `F2`, ...) on first use. Fonts are keyed by their
 * PDF canonical name so identical variants share one registration.
 *
 * @internal
 */
final class FontRegistry
{
    /** @var array<string, string> PDF canonical name => short name (e.g. "F1") */
    private array $shortNames = [];

    /** @var array<string, Font> PDF canonical name => Font instance */
    private array $fonts = [];

    public function shortName(Font $font): string
    {
        $pdfName = $font->pdfName();
        if (!isset($this->shortNames[$pdfName])) {
            $nextIndex = count($this->shortNames) + 1;
            $this->shortNames[$pdfName] = 'F' . $nextIndex;
            $this->fonts[$pdfName] = $font;
        }
        return $this->shortNames[$pdfName];
    }

    public function isEmpty(): bool
    {
        return $this->fonts === [];
    }

    /**
     * @return list<Font>
     */
    public function registeredFonts(): array
    {
        return array_values($this->fonts);
    }
}
