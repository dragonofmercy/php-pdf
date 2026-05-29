<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown\Node;

/**
 * A single list item, a container of block-level nodes.
 *
 * @property-read list<BlockNode> $blocks
 */
final readonly class ListItem
{
    /**
     * @param list<BlockNode> $blocks
     */
    public function __construct(
        public array $blocks,
    ) {
    }
}
