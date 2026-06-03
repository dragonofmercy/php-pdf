<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Table;

use DragonOfMercy\PhpPdf\Page;

/** Outcome of Page::table(): final anchor and span metrics. */
final readonly class TableResult
{
    public function __construct(
        public float $x,
        public float $y,
        public int $rowCount,
        public int $pageCount,
        public ?Page $page = null,
    ) {}
}
