<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom\Cff;

/**
 * Charset table (Adobe TN #5176 section 13). The expanded map (GID -> SID
 * for name-keyed / GID -> CID for CID-keyed) is exposed for tests and
 * subsetter logic, but the writer re-emits $rawBytes verbatim (the original
 * on-disk encoding) to preserve format and round-trip determinism.
 * GID 0 is .notdef and is implicit per the spec; this DTO keeps it explicit
 * at index 0 too with value 0. $format (0, 1 or 2) is informational.
 *
 * @internal
 */
final readonly class CffCharset
{
    /** @param array<int, int> $gidToNameOrCid */
    public function __construct(
        public array $gidToNameOrCid,
        public int $format,
        public string $rawBytes,
    ) {}
}
