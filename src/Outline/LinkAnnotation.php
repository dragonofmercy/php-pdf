<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Outline;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * One link annotation declared on a page. Carries the clickable rectangle in
 * the document's user unit (top-down Y, same convention as `Page::cell()`)
 * and the `Link` payload (URI or internal destination). The Y-flip and
 * `/Rect` serialisation happen later in {@see LinkAnnotationEmitter}.
 *
 * This VO is produced by `Page::link()` (untagged area links) and by
 * `Page::cell(link:)` (which may also tag it for PDF/UA). Users do not
 * construct it directly. Width / height positivity is enforced before this VO
 * is built, so the constructor itself does no validation.
 *
 * When the annotation participates in the logical structure tree, it carries a
 * 0-based `$structParentTagIndex` (used to derive its `/StructParent` key at
 * emit time) and the human-readable `$contents` (`/Contents`). Both stay null
 * for untagged links.
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
        public ?int $structParentTagIndex = null,
        public ?string $contents = null,
    ) {}

    /** Whether this annotation is tagged into the logical structure tree. */
    public function isTagged(): bool
    {
        return $this->structParentTagIndex !== null;
    }

    /**
     * The annotation's /StructParent key (also the ParentTree key the owning
     * <Link> element resolves through). Annotation keys sit after the per-page
     * MCID keys 0..pageCount-1, so the key is $pageCount + $structParentTagIndex.
     *
     * @throws PdfException when called on an untagged annotation
     */
    public function structParentKey(int $pageCount): int
    {
        if ($this->structParentTagIndex === null) {
            throw new PdfException('structParentKey on an untagged link annotation');
        }
        return $pageCount + $this->structParentTagIndex;
    }
}
