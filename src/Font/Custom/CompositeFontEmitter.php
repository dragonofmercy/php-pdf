<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;

/**
 * Assembles the five PDF objects required to embed one TrueType font as a
 * Type0 composite font (PDF 1.7 9.7):
 *   - Type0 font dict (Encoding=Identity-H, references CIDFont and ToUnicode)
 *   - CIDFontType2 dict (references FontDescriptor, CIDToGIDMap=Identity, /W)
 *   - FontDescriptor dict (references FontFile2)
 *   - FontFile2 stream (FlateDecode-compressed TTF bytes, /Length1=raw size)
 *   - ToUnicode CMap stream (FlateDecode-compressed)
 *
 * @internal
 */
final class CompositeFontEmitter extends AbstractCompositeFontEmitter
{
    protected function cidFontSubtype(): string
    {
        return 'CIDFontType2';
    }

    protected function hasCidToGidMap(): bool
    {
        return true;
    }

    protected function fontFileKey(): string
    {
        return 'FontFile2';
    }

    /**
     * @param list<int> $presentGids GIDs embedded in the subset, used to build the optional /CIDSet
     * @return array{type0: IndirectObject, cidFont: IndirectObject, descriptor: IndirectObject, fontFile: IndirectObject, toUnicode: IndirectObject, cidSet?: IndirectObject}
     */
    public function emit(
        ParsedTtf $font,
        SubsettedFont $subset,
        int $type0Id,
        int $cidFontId,
        int $descriptorId,
        int $fontFileId,
        int $toUnicodeId,
        array $presentGids = [],
        ?int $cidSetId = null,
    ): array {
        $baseFont = Name::of($subset->prefixedPostScriptName);

        $objects = [
            'type0' => IndirectObject::of($type0Id, 0, $this->buildType0($baseFont, $cidFontId, $toUnicodeId)),
            'cidFont' => IndirectObject::of($cidFontId, 0, $this->buildCidFont($font, $baseFont, $descriptorId)),
            'descriptor' => IndirectObject::of($descriptorId, 0, $this->buildDescriptor($font, $baseFont, $fontFileId, $cidSetId)),
            'fontFile' => IndirectObject::of($fontFileId, 0, $this->buildFontFile($subset)),
            'toUnicode' => IndirectObject::of($toUnicodeId, 0, $this->buildToUnicode($font)),
        ];
        if ($cidSetId !== null) {
            $objects['cidSet'] = IndirectObject::of($cidSetId, 0, $this->buildCidSet($presentGids));
        }
        return $objects;
    }

    private function buildFontFile(SubsettedFont $subset): FontStream
    {
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Length1'), PdfNumber::ofInt(strlen($subset->subsettedBytes)))
            ->withEntry(Name::of('Filter'), Name::of('FlateDecode'));
        return new FontStream($dict, $this->deflate($subset->subsettedBytes, 'FontFile2'));
    }
}
