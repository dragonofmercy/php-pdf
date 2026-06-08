<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown;

use DragonOfMercy\PhpPdf\Markdown\Node\ImageSpan;

/**
 * A paragraph whose entire content is a single image, optionally wrapped in a
 * link (`[![alt](img)](url)`). Produced by {@see BoxRenderer::soleImage()} to
 * drive block-level image placement (and tagged image links).
 *
 * @internal
 */
final readonly class SoleImage
{
    public function __construct(
        public ImageSpan $image,
        public ?string $linkUrl,
    ) {}
}
