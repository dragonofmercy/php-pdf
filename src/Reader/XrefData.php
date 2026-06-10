<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;

/**
 * Result of reading cross-reference data: entries keyed by object number
 * plus the trailer dictionary. Used both for a single section and for the
 * merged view across /Prev revisions.
 *
 * @internal
 */
final readonly class XrefData
{
    /** @param array<int, XrefEntry> $entries */
    public function __construct(
        public array $entries,
        public Dictionary $trailer,
    ) {}
}
