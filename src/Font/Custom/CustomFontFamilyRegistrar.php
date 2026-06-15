<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;

/**
 * Shared custom-font-family registration logic for Document and PdfEditor.
 * Stateless: the caller owns the $families map (and its fontResolver); this
 * helper validates the alias, parses each variant eagerly, mutates $families
 * in place, and returns the freshly built FontResolver.
 */
final class CustomFontFamilyRegistrar
{
    /**
     * @param array<string, array{regular: ParsedTtf, bold: ?ParsedTtf, italic: ?ParsedTtf, boldItalic: ?ParsedTtf}> $families
     */
    public static function register(
        array &$families,
        string $alias,
        string $regular,
        ?string $bold,
        ?string $italic,
        ?string $boldItalic,
        MetricsRegistry $metrics,
        GlyphUsage $glyphUsage,
    ): FontResolver {
        if (isset($families[$alias])) {
            throw new PdfException("Font family '{$alias}' is already registered; each alias can be registered only once");
        }
        $families[$alias] = [
            'regular' => self::parseFontFile($alias, 'regular', $regular),
            'bold' => $bold !== null ? self::parseFontFile($alias, 'bold', $bold) : null,
            'italic' => $italic !== null ? self::parseFontFile($alias, 'italic', $italic) : null,
            'boldItalic' => $boldItalic !== null ? self::parseFontFile($alias, 'boldItalic', $boldItalic) : null,
        ];
        return new FontResolver($families, $metrics, $glyphUsage);
    }

    private static function parseFontFile(string $alias, string $variant, string $path): ParsedTtf
    {
        if (!is_file($path)) {
            throw new PdfException("Font file not found for alias '{$alias}' ({$variant}): {$path}");
        }
        return ParsedTtfCache::getOrParse($path, function () use ($alias, $variant, $path): ParsedTtf {
            $bytes = @file_get_contents($path);
            if ($bytes === false) {
                throw new PdfException("Cannot read font file for alias '{$alias}' ({$variant}): {$path}");
            }
            return TtfParser::parse($bytes, "{$alias} ({$variant})");
        });
    }
}
