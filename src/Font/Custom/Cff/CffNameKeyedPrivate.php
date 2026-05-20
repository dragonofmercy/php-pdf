<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom\Cff;

/**
 * Private DICT (Adobe TN #5176 section 15) plus local Subrs INDEX (section
 * 16) for one Font DICT. Reused both for name-keyed fonts (one Private,
 * stored in CffTopDictData::$namePrivate) and for each FD in CID-keyed fonts
 * (stored as a list inside CffCidKeyed::$fdPrivates). Both pieces are kept
 * intact through the Standard subsetter and rewritten verbatim by the writer
 * - only their absolute offsets are recomputed.
 *
 * @internal
 */
final readonly class CffNameKeyedPrivate
{
    /**
     * @param array<string, int|float|array<int, int|float>> $privateDict operator name => operand(s)
     * @param list<string>                                   $localSubrs   raw Subr charstring bytes
     */
    public function __construct(
        public array $privateDict,
        public array $localSubrs,
    ) {}
}
