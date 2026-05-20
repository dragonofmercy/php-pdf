<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom\Cff;

/**
 * Fully deserialised CFF1 font. CFF1 supports multiple Top DICTs through the
 * Top DICT INDEX, but every real-world embedded OTF/CFF carries exactly one
 * entry (CFF2 lifts this); this codebase requires Name INDEX == 1 and Top
 * DICT INDEX == 1 (otherwise PdfException). $topDicts and $topDictData are
 * still lists for symmetry with the wire format.
 *
 * @internal
 */
final readonly class ParsedCff
{
    /**
     * @param list<array<string, int|float|array<int, int|float>>> $topDicts    parsed Top DICTs
     * @param list<string>                                         $stringIndex  raw String INDEX entries
     * @param list<string>                                         $gsubrsIndex  raw GSubrs INDEX entries
     * @param list<CffTopDictData>                                 $topDictData  structured per-Top-DICT payload
     */
    public function __construct(
        public CffHeader $header,
        public string $nameIndexEntry,
        public array $topDicts,
        public array $stringIndex,
        public array $gsubrsIndex,
        public array $topDictData,
    ) {}
}
