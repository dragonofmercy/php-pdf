<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill\Font;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\GlyphList;
use DragonOfMercy\PhpPdf\Font\WinAnsiEncoder;
use DragonOfMercy\PhpPdf\Reader\DictReader;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;

/**
 * Parses a PDF simple font dictionary (Type1 / TrueType / MMType1) into a
 * SimpleFontProgram: a width table and a unicode-to-byte-code map for use
 * when generating AcroForm field appearances.
 *
 * @internal
 */
final class SimpleFontDictReader
{
    private const array SUPPORTED_SUBTYPES = ['Type1', 'TrueType', 'MMType1'];

    public static function read(Dictionary $font, PdfReader $reader, string $fieldName): SimpleFontProgram
    {
        $resolve = fn (PdfObject $o): PdfObject => $reader->resolve($o);

        // Validate /Subtype
        $subtype = DictReader::name($font, 'Subtype', $resolve);
        if ($subtype === null || !in_array($subtype, self::SUPPORTED_SUBTYPES, true)) {
            throw new PdfException(sprintf(
                'Field "%s": unsupported font /Subtype "%s"; expected Type1, TrueType, or MMType1',
                $fieldName,
                $subtype ?? '(missing)',
            ));
        }

        // /FirstChar and /Widths
        $firstChar = DictReader::int($font, 'FirstChar', $resolve) ?? 0;
        $widths = DictReader::intList($font, 'Widths', $resolve);
        if ($widths === null) {
            throw new PdfException(sprintf(
                'Field "%s": font dictionary is missing required /Widths entry',
                $fieldName,
            ));
        }

        // Build codeWidths map
        $codeWidths = [];
        foreach ($widths as $i => $w) {
            $codeWidths[$firstChar + $i] = $w;
        }

        // /MissingWidth from /FontDescriptor
        $missingWidth = 0;
        $descriptor = DictReader::dictionary($font, 'FontDescriptor', $resolve);
        if ($descriptor !== null) {
            $missingWidth = DictReader::int($descriptor, 'MissingWidth', $resolve) ?? 0;
        }

        // Build unicodeToCode from /Encoding
        $unicodeToCode = self::buildUnicodeToCode($font, $reader, $resolve, $fieldName);

        return new SimpleFontProgram($codeWidths, $missingWidth, $unicodeToCode);
    }

    /**
     * Builds the unicode -> byte code map from the /Encoding entry.
     *
     * @param \Closure(PdfObject): PdfObject $resolve
     * @return array<int, int>
     */
    private static function buildUnicodeToCode(Dictionary $font, PdfReader $reader, \Closure $resolve, string $fieldName): array
    {
        $enc = $font->get(Name::of('Encoding'));
        if ($enc !== null) {
            $enc = $resolve($enc);
        }

        if ($enc === null) {
            // Absent /Encoding: use WinAnsi as the base with no Differences
            return self::winAnsiUnicodeToCode();
        }

        if ($enc instanceof Name) {
            if ($enc->value() !== 'WinAnsiEncoding') {
                throw new PdfException(sprintf(
                    'Field "%s": unsupported base encoding "%s"; only WinAnsiEncoding is supported',
                    $fieldName,
                    $enc->value(),
                ));
            }
            return self::winAnsiUnicodeToCode();
        }

        if ($enc instanceof Dictionary) {
            return self::buildFromEncodingDict($enc, $resolve, $fieldName);
        }

        // Unknown /Encoding type - fall back to WinAnsi
        return self::winAnsiUnicodeToCode();
    }

    /**
     * Builds the map from a /Encoding dictionary (with optional /BaseEncoding and /Differences).
     *
     * @param \Closure(PdfObject): PdfObject $resolve
     * @return array<int, int>
     */
    private static function buildFromEncodingDict(Dictionary $enc, \Closure $resolve, string $fieldName): array
    {
        $baseName = DictReader::name($enc, 'BaseEncoding', $resolve);

        // Default base when absent: WinAnsi (per PDF spec, absent BaseEncoding in a font
        // dict defaults to the font's built-in encoding; we conservatively use WinAnsi)
        if ($baseName === null || $baseName === 'WinAnsiEncoding') {
            $base = self::winAnsiUnicodeToCode();
        } else {
            throw new PdfException(sprintf(
                'Field "%s": unsupported base encoding "%s" in /Encoding dictionary; only WinAnsiEncoding is supported',
                $fieldName,
                $baseName,
            ));
        }

        // Apply /Differences: alternating int (starting code) then glyph names
        $diffsEntry = $enc->get(Name::of('Differences'));
        if ($diffsEntry === null) {
            return $base;
        }
        $diffsEntry = $resolve($diffsEntry);
        if (!$diffsEntry instanceof PdfArray) {
            return $base;
        }

        $code = 0;
        foreach ($diffsEntry->elements() as $element) {
            $element = $resolve($element);
            if ($element instanceof PdfNumber) {
                $v = $element->value();
                $code = is_int($v) ? $v : (int) $v;
            } elseif ($element instanceof Name) {
                $glyphName = $element->value();
                $unicode = GlyphList::codepoint($glyphName);
                if ($unicode !== null) {
                    $base[$unicode] = $code;
                }
                $code++;
            }
        }

        return $base;
    }

    /**
     * Returns the WinAnsi unicode -> byte code map (inverse of WinAnsiEncoder::encode).
     *
     * @return array<int, int>
     */
    private static function winAnsiUnicodeToCode(): array
    {
        return WinAnsiEncoder::unicodeToCodeMap();
    }
}
