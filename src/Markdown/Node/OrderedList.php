<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown\Node;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * An ordered (numbered) list with a start index.
 *
 * @property-read list<ListItem> $items
 *
 * @internal
 */
final readonly class OrderedList implements BlockNode
{
    /**
     * @param list<ListItem> $items
     */
    public function __construct(
        public int $start,
        public array $items,
        public bool $tight,
    ) {
        if ($start < 0) {
            throw new PdfException("Ordered list start must be >= 0, got {$start}");
        }
    }
}
