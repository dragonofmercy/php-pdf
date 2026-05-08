<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font;

use DragonOfMercy\PhpPdf\Font;

/**
 * Ordered registry of fonts used across all pages of a document. Attribute
 * short PDF names (`F1`, `F2`, ...) on first use. Standard fonts are keyed
 * by their PDF canonical name; custom fonts are keyed by an externally
 * resolved TTF identifier (typically "{alias}:{variant}" or the PostScriptName)
 * so that two Font instances pointing to the same physical TTF share a name.
 *
 * @internal
 */
final class FontRegistry
{
    /** @var array<string, string> internal key => short name (F1, F2, ...) */
    private array $shortNames = [];

    /** @var array<string, Font> standard key ('std:...') => Font instance */
    private array $standardFonts = [];

    /** @var array<string, string> custom key ('custom:...') => resolved TTF identifier */
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

    public function shortNameForCustom(Font $font, string $resolvedTtfId): string
    {
        $key = 'custom:' . $resolvedTtfId;
        if (!isset($this->shortNames[$key])) {
            $this->shortNames[$key] = 'F' . (count($this->shortNames) + 1);
            $this->customFonts[$key] = $resolvedTtfId;
        }
        return $this->shortNames[$key];
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
     * @return array<string, string> short name => resolved TTF identifier
     */
    public function customRegistrations(): array
    {
        $result = [];
        foreach ($this->customFonts as $key => $ttfId) {
            $result[$this->shortNames[$key]] = $ttfId;
        }
        return $result;
    }
}
