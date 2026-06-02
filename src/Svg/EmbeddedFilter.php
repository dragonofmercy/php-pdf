<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * A rasterized + filtered SVG subtree, ready to be embedded as an image
 * XObject. The renderer produces one per filtered element; ImageEmbedder
 * allocates the indirect object and names it /ImF{index}.
 *
 * @internal
 */
final readonly class EmbeddedFilter
{
    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $placement
     *        [x, y, w, h] rectangle in the child-local user space where the
     *        image is placed.
     */
    public function __construct(
        public int $widthPx,
        public int $heightPx,
        public string $colorBytes,
        public string $alphaBytes,
        public array $placement,
    ) {}
}
