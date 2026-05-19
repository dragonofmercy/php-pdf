<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;

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
 * subset prefix.
 *
 * @internal
 */
final class OpenTypeFontEmitter extends AbstractCompositeFontEmitter
{
    protected function cidFontSubtype(): string
    {
        return 'CIDFontType0';
    }

    protected function hasCidToGidMap(): bool
    {
        return false;
    }

    protected function fontFileKey(): string
    {
        return 'FontFile3';
    }

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

        return [
            'type0' => IndirectObject::of($type0Id, 0, $this->buildType0($baseFont, $cidFontId, $toUnicodeId)),
            'cidFont' => IndirectObject::of($cidFontId, 0, $this->buildCidFont($font, $baseFont, $descriptorId)),
            'descriptor' => IndirectObject::of($descriptorId, 0, $this->buildDescriptor($font, $baseFont, $fontFileId)),
            'fontFile' => IndirectObject::of($fontFileId, 0, $this->buildFontFile($font)),
            'toUnicode' => IndirectObject::of($toUnicodeId, 0, $this->buildToUnicode($font)),
        ];
    }

    private function buildFontFile(ParsedTtf $font): FontStream
    {
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Subtype'), Name::of('OpenType'))
            ->withEntry(Name::of('Filter'), Name::of('FlateDecode'));
        return new FontStream($dict, $this->deflate($font->bytes, 'FontFile3'));
    }
}
