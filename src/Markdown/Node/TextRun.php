<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown\Node;

/**
 * A run of inline text carrying its formatting flags.
 */
final readonly class TextRun implements InlineNode
{
    public function __construct(
        public string $text,
        public bool $bold,
        public bool $italic,
        public bool $code,
    ) {
    }
}
