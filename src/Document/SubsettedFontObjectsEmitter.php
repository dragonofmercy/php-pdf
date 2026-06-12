<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Document;

use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffOpenTypeSubsetter;
use DragonOfMercy\PhpPdf\Font\Custom\CompositeFontEmitter;
use DragonOfMercy\PhpPdf\Font\Custom\CustomFontKey;
use DragonOfMercy\PhpPdf\Font\Custom\GlyphClosure;
use DragonOfMercy\PhpPdf\Font\Custom\GlyphUsage;
use DragonOfMercy\PhpPdf\Font\Custom\OpenTypeFontEmitter;
use DragonOfMercy\PhpPdf\Font\Custom\OutlineFormat;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\Custom\SubsetTag;
use DragonOfMercy\PhpPdf\Font\Custom\SubsettedFont;
use DragonOfMercy\PhpPdf\Font\Custom\TtfSubsetter;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;

/**
 * Emits the indirect objects for embedded, subsetted custom fonts.
 *
 * For each registered custom font: computes the used-glyph closure, subsets the
 * outlines (CFF or TrueType, GID-preserving), derives the deterministic subset
 * tag, and delegates to the matching font emitter. Emits five objects per font
 * (type0, cidFont, descriptor, fontFile, toUnicode) in that order.
 *
 * @internal
 */
final readonly class SubsettedFontObjectsEmitter
{
    public function __construct(private readonly GlyphUsage $glyphUsage)
    {
    }

    /**
     * @param list<array{ParsedTtf, CustomFontKey, int, int, int, int, int, ?int}> $customEmissions
     *        each tuple is [parsedTtf, key, type0Id, cidFontId, descriptorId, fontFileId, toUnicodeId, cidSetId];
     *        the trailing cidSetId is non-null only when PDF/A-1 requires a /CIDSet stream.
     * @return list<IndirectObject>
     */
    public function emit(array $customEmissions): array
    {
        if ($customEmissions === []) {
            return [];
        }

        $objects = [];
        $ttfEmitter = new CompositeFontEmitter();
        $otfEmitter = new OpenTypeFontEmitter();
        $cffSubsetter = new CffOpenTypeSubsetter();

        foreach ($customEmissions as [$parsed, $key, $t0, $cf, $desc, $ff, $tu, $cidSetId]) {
            $context = $parsed->postScriptName;
            $used = $this->glyphUsage->usedGids($key->toRegistryKey());
            if ($parsed->outlineFormat === OutlineFormat::Cff) {
                // CFF outlines: GID-preserving subset of CharStrings INDEX only
                // (closure = used GIDs + notdef GID 0). All other CFF tables are
                // copied verbatim by CffWriter; FontFile3 carries the rebuilt
                // sfnt and BaseFont/FontName get the deterministic subset tag.
                $closure = $used + [0 => true];
                $sortedGids = array_keys($closure);
                sort($sortedGids);
                $subsetBytes = $cffSubsetter->subset($parsed->bytes, $closure, $context);
                $tag = SubsetTag::derive($context, $sortedGids);
                $subset = new SubsettedFont($subsetBytes, $tag . '+' . $context);
                $emitted = $otfEmitter->emit($parsed, $subset, $t0, $cf, $desc, $ff, $tu, $sortedGids, $cidSetId);
            } else {
                // TrueType outlines: GID-preserving subset + derived tag (Phase 3b path).
                $closure = GlyphClosure::expand($parsed->bytes, $used, $context);
                $sortedGids = array_keys($closure);
                sort($sortedGids); // makes tag derivation independent of GlyphClosure's internal insertion order
                $subsetBytes = TtfSubsetter::subset($parsed->bytes, $closure, $context);
                $tag = SubsetTag::derive($context, $sortedGids);
                $subset = new SubsettedFont($subsetBytes, $tag . '+' . $context);
                $emitted = $ttfEmitter->emit($parsed, $subset, $t0, $cf, $desc, $ff, $tu, $sortedGids, $cidSetId);
            }
            $objects[] = $emitted['type0'];
            $objects[] = $emitted['cidFont'];
            $objects[] = $emitted['descriptor'];
            $objects[] = $emitted['fontFile'];
            $objects[] = $emitted['toUnicode'];
            if (isset($emitted['cidSet'])) {
                $objects[] = $emitted['cidSet'];
            }
        }

        return $objects;
    }
}
