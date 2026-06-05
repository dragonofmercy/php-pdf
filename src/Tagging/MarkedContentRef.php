<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tagging;

/**
 * A leaf in the structure tree: a marked-content sequence identified by its
 * page index and per-page MCID.
 */
final readonly class MarkedContentRef
{
    public function __construct(
        public int $pageIndex,
        public int $mcid,
    ) {}
}
