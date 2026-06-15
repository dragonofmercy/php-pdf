<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill\Font;

use DragonOfMercy\PhpPdf\Exception\PdfException;
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

    public function read(Dictionary $font, PdfReader $reader, string $fieldName): SimpleFontProgram
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
        $unicodeToCode = $this->buildUnicodeToCode($font, $reader, $resolve, $fieldName);

        return new SimpleFontProgram($codeWidths, $missingWidth, $unicodeToCode);
    }

    /**
     * Builds the unicode -> byte code map from the /Encoding entry.
     *
     * @param \Closure(PdfObject): PdfObject $resolve
     * @return array<int, int>
     */
    private function buildUnicodeToCode(Dictionary $font, PdfReader $reader, \Closure $resolve, string $fieldName): array
    {
        $enc = $font->get(Name::of('Encoding'));
        if ($enc !== null) {
            $enc = $resolve($enc);
        }

        if ($enc === null || ($enc instanceof Name && $enc->value() === 'WinAnsiEncoding')) {
            // Use WinAnsi as the base with no Differences
            return self::winAnsiUnicodeToCode();
        }

        if ($enc instanceof Name) {
            $baseName = $enc->value();
            if ($baseName !== 'WinAnsiEncoding') {
                throw new PdfException(sprintf(
                    'Field "%s": unsupported base encoding "%s"; only WinAnsiEncoding is supported',
                    $fieldName,
                    $baseName,
                ));
            }
            return self::winAnsiUnicodeToCode();
        }

        if ($enc instanceof Dictionary) {
            return $this->buildFromEncodingDict($enc, $resolve, $fieldName);
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
    private function buildFromEncodingDict(Dictionary $enc, \Closure $resolve, string $fieldName): array
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
     * WinAnsiEncoding:
     *   - Printable ASCII 0x20-0x7E: unicode == byte code
     *   - Latin-1 Supplement 0xA0-0xFF: unicode == byte code
     *   - Special 0x80-0x9F range: mapped from specific Unicode codepoints
     *
     * @return array<int, int>
     */
    private static function winAnsiUnicodeToCode(): array
    {
        // Printable ASCII: 0x20-0x7E
        $map = [];
        for ($code = 0x20; $code <= 0x7E; $code++) {
            $map[$code] = $code;
        }
        // Latin-1 Supplement: 0xA0-0xFF (unicode codepoint == byte code)
        for ($code = 0xA0; $code <= 0xFF; $code++) {
            $map[$code] = $code;
        }
        // Special 0x80-0x9F range (from WinAnsiEncoder::MAP, inverted)
        $map[0x20AC] = 0x80; // Euro
        $map[0x201A] = 0x82; // quotesinglbase
        $map[0x0192] = 0x83; // florin
        $map[0x201E] = 0x84; // quotedblbase
        $map[0x2026] = 0x85; // ellipsis
        $map[0x2020] = 0x86; // dagger
        $map[0x2021] = 0x87; // daggerdbl
        $map[0x02C6] = 0x88; // circumflex
        $map[0x2030] = 0x89; // perthousand
        $map[0x0160] = 0x8A; // Scaron
        $map[0x2039] = 0x8B; // guilsinglleft
        $map[0x0152] = 0x8C; // OE
        $map[0x017D] = 0x8E; // Zcaron
        $map[0x2018] = 0x91; // quoteleft
        $map[0x2019] = 0x92; // quoteright
        $map[0x201C] = 0x93; // quotedblleft
        $map[0x201D] = 0x94; // quotedblright
        $map[0x2022] = 0x95; // bullet
        $map[0x2013] = 0x96; // endash
        $map[0x2014] = 0x97; // emdash
        $map[0x02DC] = 0x98; // tilde
        $map[0x2122] = 0x99; // trademark
        $map[0x0161] = 0x9A; // scaron
        $map[0x203A] = 0x9B; // guilsinglright
        $map[0x0153] = 0x9C; // oe
        $map[0x017E] = 0x9E; // zcaron
        $map[0x0178] = 0x9F; // Ydieresis
        return $map;
    }
}
