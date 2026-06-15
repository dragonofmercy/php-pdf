<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill\Font;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Reader\DictReader;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;

/**
 * Selects the correct AppearanceFont implementation for a field's /DR font entry.
 *
 * - Standard 14 fonts (Helvetica, Times, Courier families) -> Standard14AppearanceFont.
 * - Simple embedded fonts (Type1 / TrueType / MMType1) -> SimpleEmbeddedAppearanceFont.
 * - Type0 composite fonts (Identity-H / CIDFontType2 / FontFile2) -> CompositeEmbeddedAppearanceFont.
 * - Any other /Subtype -> PdfException.
 *
 * @internal
 */
final class AppearanceFontFactory
{
    /**
     * Builds an AppearanceFont for a resolved DR font dictionary.
     *
     * @param PdfReader       $reader       the source document reader (used by SimpleFontDictReader)
     * @param Dictionary      $drFontDict   the resolved font dictionary from /DR /Font /<alias>
     * @param string          $baseFont     the /BaseFont name (already read by the caller)
     * @param string          $fieldName    for error messages
     * @param MetricsRegistry $metrics      for Standard-14 metric lookups
     */
    public static function forField(
        PdfReader $reader,
        Dictionary $drFontDict,
        string $baseFont,
        string $fieldName,
        MetricsRegistry $metrics,
    ): AppearanceFont {
        // 1. Standard-14 detection: exact name or prefix match (same table as the former baseFontToFont).
        $std14 = self::detectStandard14($baseFont);
        if ($std14 !== null) {
            return new Standard14AppearanceFont($std14, $metrics);
        }

        // 2. Not Standard-14: inspect /Subtype of the font dictionary.
        $resolve = $reader->resolve(...);
        $subtype = DictReader::name($drFontDict, 'Subtype', $resolve);

        return match ($subtype) {
            'Type1', 'TrueType', 'MMType1' => new SimpleEmbeddedAppearanceFont(
                SimpleFontDictReader::read($drFontDict, $reader, $fieldName),
                $fieldName,
            ),
            'Type0' => new CompositeEmbeddedAppearanceFont(
                CompositeFontDictReader::read($drFontDict, $reader, $fieldName),
                $fieldName,
            ),
            default => throw new PdfException(
                "Field '{$fieldName}': unsupported font /Subtype \"" . ($subtype ?? '(missing)') . '"',
            ),
        };
    }

    /**
     * Maps a /BaseFont name to the corresponding Standard-14 Font instance, or
     * returns null when the name does not belong to one of the three Standard-14 families.
     *
     * The detection table mirrors the former FieldValueApplier::baseFontToFont so that
     * the Standard-14 appearance path remains byte-identical to the pre-factory behavior.
     */
    private static function detectStandard14(string $baseFont): ?Font
    {
        // Each row: [exact base name, prefix (for variant names), factory, italic keyword].
        // A name matches when it equals exactBase OR starts with "prefix-".
        $table = [
            ['Helvetica', 'Helvetica', Font::helvetica(...), 'Oblique'],
            ['Times-Roman', 'Times', Font::times(...), 'Italic'],
            ['Courier', 'Courier', Font::courier(...), 'Oblique'],
        ];

        foreach ($table as [$exactBase, $prefix, $factory, $italicKeyword]) {
            if ($baseFont === $exactBase || str_starts_with($baseFont, $prefix . '-')) {
                $font = $factory();
                if (str_contains($baseFont, 'Bold')) {
                    $font = $font->bold();
                }
                if (str_contains($baseFont, $italicKeyword)) {
                    $font = $font->italic();
                }
                return $font;
            }
        }

        return null;
    }
}
