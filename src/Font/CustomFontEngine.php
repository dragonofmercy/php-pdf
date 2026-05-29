<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\Custom\CustomFontKey;
use DragonOfMercy\PhpPdf\Font\Custom\GlyphUsage;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\Custom\Utf8;
use DragonOfMercy\PhpPdf\Font\Custom\Utf8ToCidEncoder;
use DragonOfMercy\PhpPdf\Page\ContentStream;
use DragonOfMercy\PhpPdf\Page\Operators;

/**
 * FontEngine for custom TTF fonts. Wraps a ParsedTtf and emits Identity-H
 * hex-encoded show-text operators byte-identical to the pre-engine path.
 *
 * @internal
 */
final readonly class CustomFontEngine implements FontEngine
{
    private CustomFontKey $key;

    public function __construct(
        private Font $font,
        private ParsedTtf $ttf,
        private GlyphUsage $glyphUsage,
    ) {
        $this->key = new CustomFontKey($font->requireCustomAlias(), $ttf->postScriptName);
    }

    public function font(): Font
    {
        return $this->font;
    }

    public function measure(string $text, float $size): float
    {
        if ($text === '') {
            return 0.0;
        }
        $totalEm = 0;
        foreach (Utf8::codepoints($text) as [$cp, $_]) {
            $gid = $cp >= 0 ? ($this->ttf->cmap[$cp] ?? 0) : 0;
            $totalEm += $this->ttf->advanceWidthsByGid[$gid] ?? 0;
        }
        return $totalEm * $size / $this->ttf->unitsPerEm;
    }

    public function encodeShowText(string $text): string
    {
        return Operators::showTextHex($this->encodeHex($text));
    }

    public function emitShowText(ContentStream $stream, string $text): void
    {
        $stream->append($this->encodeShowText($text));
    }

    public function emitShowTextNextLine(ContentStream $stream, string $text): void
    {
        $stream->append(Operators::showTextHexNextLine($this->encodeHex($text)));
    }

    private function encodeHex(string $text): string
    {
        [$bytes, $gids] = Utf8ToCidEncoder::encodeWithGids($text, $this->ttf);
        $usageKey = $this->usageKey();
        foreach ($gids as $gid) {
            $this->glyphUsage->record($usageKey, $gid);
        }
        return strtoupper(bin2hex($bytes));
    }

    public function splitForceBreak(string $token, float $innerW, float $size): array
    {
        $chunks = [];
        $widths = [];
        $current = '';
        $currentWidth = 0.0;

        $offset = 0;
        foreach (Utf8::codepoints($token) as [$_, $cpLen]) {
            $charBytes = substr($token, $offset, $cpLen);
            $offset += $cpLen;
            $charWidth = $this->measure($charBytes, $size);
            if ($currentWidth + $charWidth > $innerW + 0.0001 && $current !== '') {
                $chunks[] = $current;
                $widths[] = $currentWidth;
                $current = $charBytes;
                $currentWidth = $charWidth;
            } else {
                $current .= $charBytes;
                $currentWidth += $charWidth;
            }
        }
        $chunks[] = $current;
        $widths[] = $currentWidth;

        return [$chunks, $widths];
    }

    public function ascentAt(float $size): float
    {
        return $this->ttf->ascent * $size / $this->ttf->unitsPerEm;
    }

    public function descentAt(float $size): float
    {
        return $this->ttf->descent * $size / $this->ttf->unitsPerEm;
    }

    public function capHeightAt(float $size): float
    {
        return $this->ttf->capHeight * $size / $this->ttf->unitsPerEm;
    }

    public function xHeightAt(float $size): float
    {
        return $this->ttf->xHeight * $size / $this->ttf->unitsPerEm;
    }

    public function registerOn(FontRegistry $registry): string
    {
        return $registry->shortNameForCustom($this->font, $this->key);
    }

    public function usageKey(): string
    {
        return $this->key->toRegistryKey();
    }
}
