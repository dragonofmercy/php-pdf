<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown\Node;

/**
 * An unordered (bullet) list.
 *
 * @property-read list<ListItem> $items
 *
 * @internal
 */
final readonly class BulletList implements BlockNode
{
    /**
     * @param list<ListItem> $items
     */
    public function __construct(
        public array $items,
        public bool $tight,
    ) {
    }
}
