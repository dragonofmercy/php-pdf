<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Outline;

/**
 * One link annotation declared on a page. Carries the clickable rectangle in
 * the document's user unit (top-down Y, same convention as `Page::cell()`)
 * and the `Link` payload (URI or internal destination). The Y-flip and
 * `/Rect` serialisation happen later in {@see LinkAnnotationEmitter}.
 *
 * This VO is produced exclusively by `Page::link()`; users do not construct
 * it directly. Width / height positivity is enforced by `Page::link()` before
 * this VO is built, so the constructor itself does no validation.
 *
 * @internal
 */
final readonly class LinkAnnotation
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public Link $link,
    ) {}
}
