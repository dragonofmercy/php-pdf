<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom\Cff;

/**
 * CharStrings INDEX (Adobe TN #5176 section 14). After parsing this is dense
 * (every GID in 0..numGlyphs-1 has an entry); after subsetting it is sparse
 * (only GIDs in the closure remain). $numGlyphs is preserved across the
 * subset pass so the writer can emit a numGlyphs+1 offset table with empty
 * entries (length 0) at GIDs outside the closure - the mechanism that makes
 * GID-preserving Standard subsetting possible.
 *
 * @internal
 */
final readonly class CffCharStrings
{
    /** @param array<int, string> $glyphs GID -> Type2 charstring bytes */
    public function __construct(
        public array $glyphs,
        public int $numGlyphs,
    ) {}
}
