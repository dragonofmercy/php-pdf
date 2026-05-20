<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom\Cff;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Reduces a ParsedCff to a subsetted ParsedCff. The Standard aggressiveness
 * level only reduces CharStrings.glyphs to the closure; charset, encoding,
 * Private DICT(s), local Subrs, GSubrs, FDArray and FDSelect are kept
 * intact. numGlyphs is preserved so the writer can emit a numGlyphs+1
 * offset table with empty entries for non-closure GIDs (GID-preserving).
 *
 * @internal
 */
final class CffSubsetter
{
    /**
     * @param array<int, true> $closure GIDs to keep (must include 0)
     * @throws PdfException if the closure references GIDs not in the font
     */
    public function subset(ParsedCff $cff, array $closure, string $context): ParsedCff
    {
        $reducedTopData = [];
        foreach ($cff->topDictData as $td) {
            $glyphs = [];
            foreach ($closure as $gid => $_) {
                if (!isset($td->charStrings->glyphs[$gid])) {
                    throw new PdfException(
                        "Closure GID {$gid} not present in CFF CharStrings for {$context}",
                    );
                }
                $glyphs[$gid] = $td->charStrings->glyphs[$gid];
            }
            $reducedTopData[] = new CffTopDictData(
                charset: $td->charset,
                encoding: $td->encoding,
                charStrings: new CffCharStrings($glyphs, $td->charStrings->numGlyphs),
                namePrivate: $td->namePrivate,
                cidKeyed: $td->cidKeyed,
            );
        }
        return new ParsedCff(
            header: $cff->header,
            nameIndexEntry: $cff->nameIndexEntry,
            topDicts: $cff->topDicts,
            stringIndex: $cff->stringIndex,
            gsubrsIndex: $cff->gsubrsIndex,
            topDictData: $reducedTopData,
        );
    }
}
