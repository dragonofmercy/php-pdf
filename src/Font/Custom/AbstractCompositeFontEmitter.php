<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use DragonOfMercy\PhpPdf\Writer\Object\RawValue;

/**
 * Shared scaffolding for the two Type0 composite emitters (TrueType subset via
 * {@see CompositeFontEmitter}, whole OpenType/CFF via {@see OpenTypeFontEmitter}).
 * The Type0 dict, FontDescriptor and ToUnicode CMap are byte-identical between
 * the two paths; only the descendant CIDFont subtype, the CIDToGIDMap presence
 * and the FontFile key/stream differ, expressed here as small overridables.
 *
 * Em-space metrics are scaled to PDF's 1000-unit em via round(v * 1000 / unitsPerEm).
 *
 * @internal
 */
abstract class AbstractCompositeFontEmitter
{
    /** CIDFontType2 for TrueType outlines, CIDFontType0 for CFF outlines. */
    abstract protected function cidFontSubtype(): string;

    /** TrueType subsets embed an explicit identity CIDToGIDMap; CFF does not. */
    abstract protected function hasCidToGidMap(): bool;

    /** FontFile2 for TrueType, FontFile3 for OpenType/CFF. */
    abstract protected function fontFileKey(): string;

    final protected function buildType0(Name $baseFont, int $cidFontId, int $toUnicodeId): Dictionary
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

    final protected function buildCidFont(ParsedTtf $font, Name $baseFont, int $descriptorId): Dictionary
    {
        $cidSystemInfo = Dictionary::empty()
            ->withEntry(Name::of('Registry'), PdfString::of('Adobe'))
            ->withEntry(Name::of('Ordering'), PdfString::of('Identity'))
            ->withEntry(Name::of('Supplement'), PdfNumber::ofInt(0));

        $cidFont = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Font'))
            ->withEntry(Name::of('Subtype'), Name::of($this->cidFontSubtype()))
            ->withEntry(Name::of('BaseFont'), $baseFont)
            ->withEntry(Name::of('CIDSystemInfo'), $cidSystemInfo)
            ->withEntry(Name::of('FontDescriptor'), PdfReference::to($descriptorId, 0));

        if ($this->hasCidToGidMap()) {
            $cidFont = $cidFont->withEntry(Name::of('CIDToGIDMap'), Name::of('Identity'));
        }

        return $cidFont->withEntry(Name::of('W'), RawValue::of(CidWidthsArray::generate($font)));
    }

    final protected function buildDescriptor(ParsedTtf $font, Name $baseFont, int $fontFileId, ?int $cidSetId = null): Dictionary
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

        $descriptor = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('FontDescriptor'))
            ->withEntry(Name::of('FontName'), $baseFont)
            ->withEntry(Name::of('Flags'), PdfNumber::ofInt($font->flags))
            ->withEntry(Name::of('FontBBox'), $bbox)
            ->withEntry(Name::of('ItalicAngle'), PdfNumber::ofInt($italicAngle))
            ->withEntry(Name::of('Ascent'), PdfNumber::ofInt($scale($font->ascent)))
            ->withEntry(Name::of('Descent'), PdfNumber::ofInt($scale($font->descent)))
            ->withEntry(Name::of('CapHeight'), PdfNumber::ofInt($scale($font->capHeight)))
            ->withEntry(Name::of('StemV'), PdfNumber::ofInt($stemV))
            ->withEntry(Name::of($this->fontFileKey()), PdfReference::to($fontFileId, 0));

        if ($cidSetId !== null) {
            $descriptor = $descriptor->withEntry(Name::of('CIDSet'), PdfReference::to($cidSetId, 0));
        }

        return $descriptor;
    }

    /**
     * Builds the /CIDSet stream a PDF/A-1 conforming file requires (ISO 19005-1
     * 6.3.5): a bit string where bit i (MSB-first) is set when CID i is present
     * in the embedded subset. Identity ordering means CID == GID, so the present
     * GIDs map directly to set bits.
     *
     * @param list<int> $presentGids GIDs embedded in the subset (must include 0)
     */
    final protected function buildCidSet(array $presentGids): FontStream
    {
        $maxGid = 0;
        foreach ($presentGids as $gid) {
            if ($gid > $maxGid) {
                $maxGid = $gid;
            }
        }
        /** @var list<int> $octets one accumulator per byte, each kept in 0-255 */
        $octets = array_fill(0, intdiv($maxGid, 8) + 1, 0);
        foreach ($presentGids as $gid) {
            $octets[intdiv($gid, 8)] |= 0x80 >> ($gid % 8);
        }
        $bytes = '';
        foreach ($octets as $octet) {
            $bytes .= chr($octet & 0xFF);
        }

        $dict = Dictionary::empty()
            ->withEntry(Name::of('Filter'), Name::of('FlateDecode'));
        return new FontStream($dict, $this->deflate($bytes, 'CIDSet'));
    }

    final protected function buildToUnicode(ParsedTtf $font): FontStream
    {
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Filter'), Name::of('FlateDecode'));
        return new FontStream($dict, $this->deflate(ToUnicodeCMap::generate($font), 'ToUnicode CMap'));
    }

    final protected function deflate(string $data, string $what): string
    {
        $compressed = gzcompress($data, 9);
        if ($compressed === false) {
            throw new PdfException("FlateDecode compression failed for {$what}");
        }
        return $compressed;
    }
}
