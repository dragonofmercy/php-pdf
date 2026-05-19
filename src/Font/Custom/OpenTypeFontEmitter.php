<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;

/**
 * Assembles the five PDF objects to embed one whole OpenType/CFF font as a
 * Type0 composite font (PDF 1.7 9.7), the CFF counterpart of
 * {@see CompositeFontEmitter}:
 *   - Type0 font dict (Encoding=Identity-H, references CIDFont and ToUnicode)
 *   - CIDFontType0 dict (references FontDescriptor, /W; NO /CIDToGIDMap)
 *   - FontDescriptor dict (references FontFile3)
 *   - FontFile3 stream (FlateDecode-compressed whole .otf, /Subtype /OpenType,
 *     NO /Length1)
 *   - ToUnicode CMap stream (FlateDecode-compressed)
 *
 * The whole sfnt is embedded verbatim (no subsetting); BaseFont carries no
 * subset prefix. Em-space metrics are scaled to PDF's 1000-unit em via
 * round(v * 1000 / unitsPerEm), identical to the TrueType emitter.
 *
 * @internal
 */
final class OpenTypeFontEmitter
{
    /**
     * @return array{type0: IndirectObject, cidFont: IndirectObject, descriptor: IndirectObject, fontFile: IndirectObject, toUnicode: IndirectObject}
     */
    public function emit(
        ParsedTtf $font,
        int $type0Id,
        int $cidFontId,
        int $descriptorId,
        int $fontFileId,
        int $toUnicodeId,
    ): array {
        $baseFont = Name::of($font->postScriptName);

        $type0 = $this->buildType0($baseFont, $cidFontId, $toUnicodeId);
        $cidFont = $this->buildCidFont($font, $baseFont, $descriptorId);
        $descriptor = $this->buildDescriptor($font, $baseFont, $fontFileId);
        $fontFile = $this->buildFontFile($font);
        $toUnicode = $this->buildToUnicode($font);

        return [
            'type0' => IndirectObject::of($type0Id, 0, $type0),
            'cidFont' => IndirectObject::of($cidFontId, 0, $cidFont),
            'descriptor' => IndirectObject::of($descriptorId, 0, $descriptor),
            'fontFile' => IndirectObject::of($fontFileId, 0, $fontFile),
            'toUnicode' => IndirectObject::of($toUnicodeId, 0, $toUnicode),
        ];
    }

    private function buildType0(Name $baseFont, int $cidFontId, int $toUnicodeId): Dictionary
    {
        return Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Font'))
            ->withEntry(Name::of('Subtype'), Name::of('Type0'))
            ->withEntry(Name::of('BaseFont'), $baseFont)
            ->withEntry(Name::of('Encoding'), Name::of('Identity-H'))
            ->withEntry(
                Name::of('DescendantFonts'),
                PdfArray::of(PdfReference::to($cidFontId, 0)),
            )
            ->withEntry(Name::of('ToUnicode'), PdfReference::to($toUnicodeId, 0));
    }

    private function buildCidFont(ParsedTtf $font, Name $baseFont, int $descriptorId): Dictionary
    {
        $cidSystemInfo = Dictionary::empty()
            ->withEntry(Name::of('Registry'), PdfString::of('Adobe'))
            ->withEntry(Name::of('Ordering'), PdfString::of('Identity'))
            ->withEntry(Name::of('Supplement'), PdfNumber::ofInt(0));

        return Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Font'))
            ->withEntry(Name::of('Subtype'), Name::of('CIDFontType0'))
            ->withEntry(Name::of('BaseFont'), $baseFont)
            ->withEntry(Name::of('CIDSystemInfo'), $cidSystemInfo)
            ->withEntry(Name::of('FontDescriptor'), PdfReference::to($descriptorId, 0))
            ->withEntry(Name::of('W'), new WidthsLiteral(CidWidthsArray::generate($font)));
    }

    private function buildDescriptor(ParsedTtf $font, Name $baseFont, int $fontFileId): Dictionary
    {
        $scale = static fn (int $v): int => (int) round($v * 1000.0 / $font->unitsPerEm);

        $bbox = PdfArray::of(
            PdfNumber::ofInt($scale($font->bbox[0])),
            PdfNumber::ofInt($scale($font->bbox[1])),
            PdfNumber::ofInt($scale($font->bbox[2])),
            PdfNumber::ofInt($scale($font->bbox[3])),
        );

        $stemV = 50 + (int) round((($font->weight - 400) ** 2) / 1000.0);
        $italicAngle = $font->italicAngle >> 16;

        return Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('FontDescriptor'))
            ->withEntry(Name::of('FontName'), $baseFont)
            ->withEntry(Name::of('Flags'), PdfNumber::ofInt($font->flags))
            ->withEntry(Name::of('FontBBox'), $bbox)
            ->withEntry(Name::of('ItalicAngle'), PdfNumber::ofInt($italicAngle))
            ->withEntry(Name::of('Ascent'), PdfNumber::ofInt($scale($font->ascent)))
            ->withEntry(Name::of('Descent'), PdfNumber::ofInt($scale($font->descent)))
            ->withEntry(Name::of('CapHeight'), PdfNumber::ofInt($scale($font->capHeight)))
            ->withEntry(Name::of('StemV'), PdfNumber::ofInt($stemV))
            ->withEntry(Name::of('FontFile3'), PdfReference::to($fontFileId, 0));
    }

    private function buildFontFile(ParsedTtf $font): FontStream
    {
        $compressed = gzcompress($font->bytes, 9);
        if ($compressed === false) {
            throw new PdfException('FlateDecode compression failed for FontFile3');
        }
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Subtype'), Name::of('OpenType'))
            ->withEntry(Name::of('Filter'), Name::of('FlateDecode'));
        return new FontStream($dict, $compressed);
    }

    private function buildToUnicode(ParsedTtf $font): FontStream
    {
        $cmap = ToUnicodeCMap::generate($font);
        $compressed = gzcompress($cmap, 9);
        if ($compressed === false) {
            throw new PdfException('FlateDecode compression failed for ToUnicode CMap');
        }
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Filter'), Name::of('FlateDecode'));
        return new FontStream($dict, $compressed);
    }
}
