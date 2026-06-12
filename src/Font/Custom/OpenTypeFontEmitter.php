<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;

/**
 * Assembles the five PDF objects to embed one subsetted OpenType/CFF font as a
 * Type0 composite font (PDF 1.7 9.7), the CFF counterpart of
 * {@see CompositeFontEmitter}:
 *   - Type0 font dict (Encoding=Identity-H, references CIDFont and ToUnicode)
 *   - CIDFontType0 dict (references FontDescriptor, /W; NO /CIDToGIDMap)
 *   - FontDescriptor dict (references FontFile3)
 *   - FontFile3 stream (FlateDecode-compressed subsetted .otf, /Subtype /OpenType,
 *     NO /Length1)
 *   - ToUnicode CMap stream (FlateDecode-compressed)
 *
 * The OTF embedded is the subsetted sfnt produced by
 * {@see Cff\CffOpenTypeSubsetter}; BaseFont/FontName carry the 6-letter
 * subset tag prefix per PDF 1.7 9.6.4.
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
            ->withEntry(Name::of('Subtype'), Name::of('OpenType'))
            ->withEntry(Name::of('Filter'), Name::of('FlateDecode'));
        return new FontStream($dict, $this->deflate($subset->subsettedBytes, 'FontFile3'));
    }
}
