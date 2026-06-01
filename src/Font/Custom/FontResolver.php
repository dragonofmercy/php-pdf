<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\CustomFontEngine;
use DragonOfMercy\PhpPdf\Font\FontEngine;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Font\StandardFontEngine;
use LogicException;

/**
 * Resolves a custom Font (alias + bold/italic flags) to a concrete ParsedTtf
 * via the fallback chain: exact match > closest weight match > regular.
 * Also produces FontEngine instances (standard or custom) for any Font.
 *
 * Decision rule for variant fallback:
 *   bold+italic -> boldItalic | bold | italic | regular
 *   bold        -> bold | regular
 *   italic      -> italic | regular
 *   regular     -> regular
 *
 * The italic-only fallback intentionally avoids dropping to bold: the user
 * asked for slant, not weight.
 *
 * @internal
 */
final class FontResolver
{
    /** @var array<int, FontEngine> identity-keyed cache for resolveEngine() */
    private array $engineCache = [];

    /** @var ?array<string, string> memoized lowercased alias => actual alias */
    private ?array $aliasCache = null;

    /**
     * @param array<string, array{regular: ParsedTtf, bold: ?ParsedTtf, italic: ?ParsedTtf, boldItalic: ?ParsedTtf}> $registrations
     */
    public function __construct(
        private readonly array $registrations,
        private readonly ?MetricsRegistry $metricsRegistry = null,
        private readonly ?GlyphUsage $glyphUsage = null,
    ) {}

    /**
     * @return array<string, string> lowercased registered alias => actual alias,
     *         for SVG font-family matching.
     */
    public function registeredAliases(): array
    {
        if ($this->aliasCache !== null) {
            return $this->aliasCache;
        }
        $map = [];
        foreach (array_keys($this->registrations) as $alias) {
            $map[strtolower($alias)] = $alias;
        }
        return $this->aliasCache = $map;
    }

    public function resolve(Font $font): ParsedTtf
    {
        if (!$font->isCustom()) {
            throw new LogicException('FontResolver::resolve() called with a non-custom Font');
        }
        $alias = $font->customAlias();
        if ($alias === null || !isset($this->registrations[$alias])) {
            throw new PdfException(
                "Font alias '" . ($alias ?? '') . "' is not registered. "
                . 'Call Document::registerFontFamily() first.',
            );
        }
        $reg = $this->registrations[$alias];

        if ($font->isBold() && $font->isItalic()) {
            return $reg['boldItalic'] ?? $reg['bold'] ?? $reg['italic'] ?? $reg['regular'];
        }
        if ($font->isBold()) {
            return $reg['bold'] ?? $reg['regular'];
        }
        if ($font->isItalic()) {
            return $reg['italic'] ?? $reg['regular'];
        }
        return $reg['regular'];
    }

    public function resolveEngine(Font $font): FontEngine
    {
        $cacheKey = spl_object_id($font);
        if (isset($this->engineCache[$cacheKey])) {
            return $this->engineCache[$cacheKey];
        }

        if ($font->isCustom()) {
            if ($this->glyphUsage === null) {
                throw new LogicException(
                    'FontResolver::resolveEngine() needs a GlyphUsage to build a CustomFontEngine',
                );
            }
            $engine = new CustomFontEngine($font, $this->resolve($font), $this->glyphUsage);
        } else {
            if ($this->metricsRegistry === null) {
                throw new LogicException(
                    'FontResolver::resolveEngine() needs a MetricsRegistry to build a StandardFontEngine',
                );
            }
            $engine = new StandardFontEngine($font, $this->metricsRegistry->metricsFor($font));
        }
        $this->engineCache[$cacheKey] = $engine;
        return $engine;
    }
}
