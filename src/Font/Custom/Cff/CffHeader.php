<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom\Cff;

/**
 * CFF header (Adobe TN #5176 section 6). The four fields are read at the very
 * start of the CFF byte stream. offSize is the size in bytes of offsets in
 * the Top DICT INDEX; on rewrite this value is recomputed.
 *
 * @internal
 */
final readonly class CffHeader
{
    public function __construct(
        public int $major,
        public int $minor,
        public int $hdrSize,
        public int $offSize,
    ) {}
}
