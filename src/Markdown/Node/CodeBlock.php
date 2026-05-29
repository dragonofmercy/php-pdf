<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown\Node;

/**
 * A fenced or indented code block with an optional language hint.
 */
final readonly class CodeBlock implements BlockNode
{
    public function __construct(
        public string $text,
        public ?string $lang,
    ) {
    }
}
