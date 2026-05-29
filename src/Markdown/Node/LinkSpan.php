<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown\Node;

/**
 * An inline hyperlink wrapping inline children.
 *
 * @property-read list<InlineNode> $children
 */
final readonly class LinkSpan implements InlineNode
{
    /**
     * @param list<InlineNode> $children
     */
    public function __construct(
        public array $children,
        public string $url,
    ) {
    }
}
