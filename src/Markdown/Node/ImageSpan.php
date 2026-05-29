<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown\Node;

/**
 * An inline image reference.
 *
 * @internal
 */
final readonly class ImageSpan implements InlineNode
{
    public function __construct(
        public string $alt,
        public string $src,
    ) {
    }
}
