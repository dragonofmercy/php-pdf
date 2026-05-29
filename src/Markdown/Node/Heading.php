<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown\Node;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * A heading block (levels 1 to 6) holding inline content.
 *
 * @property-read list<InlineNode> $inlines
 */
final readonly class Heading implements BlockNode
{
    /**
     * @param list<InlineNode> $inlines
     */
    public function __construct(
        public int $level,
        public array $inlines,
    ) {
        if ($level < 1 || $level > 6) {
            throw new PdfException("Heading level must be 1-6, got {$level}");
        }
    }
}
