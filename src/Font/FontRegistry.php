<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\Custom\CustomFontKey;

/**
 * Ordered registry of fonts used across all pages of a document. Attribute
 * short PDF names (`F1`, `F2`, ...) on first use. Standard fonts are keyed
 * by their PDF canonical name; custom fonts are keyed by a CustomFontKey
 * (alias + PostScriptName) so two Font instances pointing to the same
 * physical TTF share a name.
 *
 * @internal
 */
final class FontRegistry
{
    /** @var array<string, string> internal key => short name (F1, F2, ...) */
    private array $shortNames = [];

    /** @var array<string, Font> standard key ('std:...') => Font instance */
    private array $standardFonts = [];

    /** @var array<string, CustomFontKey> custom key ('custom:...') => CustomFontKey */
    private array $customFonts = [];

    public function shortName(Font $font): string
    {
        $key = 'std:' . $font->pdfName();
        if (!isset($this->shortNames[$key])) {
            $this->shortNames[$key] = 'F' . (count($this->shortNames) + 1);
            $this->standardFonts[$key] = $font;
        }
        return $this->shortNames[$key];
    }

    public function shortNameForCustom(Font $font, CustomFontKey $key): string
    {
        $internalKey = 'custom:' . $key->toRegistryKey();
        if (!isset($this->shortNames[$internalKey])) {
            $this->shortNames[$internalKey] = 'F' . (count($this->shortNames) + 1);
            $this->customFonts[$internalKey] = $key;
        }
        return $this->shortNames[$internalKey];
    }

    public function isEmpty(): bool
    {
        return $this->shortNames === [];
    }

    /**
     * @return list<Font>
     */
    public function registeredFonts(): array
    {
        return array_values($this->standardFonts);
    }

    /**
     * @return array<string, CustomFontKey> short name => CustomFontKey
     */
    public function customRegistrations(): array
    {
        $result = [];
        foreach ($this->customFonts as $internalKey => $key) {
            $result[$this->shortNames[$internalKey]] = $key;
        }
        return $result;
    }
}
