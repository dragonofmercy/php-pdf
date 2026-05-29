<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown\Node;

/**
 * A block quote wrapping nested block-level nodes.
 *
 * @property-read list<BlockNode> $blocks
 */
final readonly class BlockQuote implements BlockNode
{
    /**
     * @param list<BlockNode> $blocks
     */
    public function __construct(
        public array $blocks,
    ) {
    }
}
