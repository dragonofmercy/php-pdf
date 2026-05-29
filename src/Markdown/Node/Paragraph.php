<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown\Node;

/**
 * A paragraph block holding inline content.
 *
 * @property-read list<InlineNode> $inlines
 */
final readonly class Paragraph implements BlockNode
{
    /**
     * @param list<InlineNode> $inlines
     */
    public function __construct(
        public array $inlines,
    ) {
    }
}
