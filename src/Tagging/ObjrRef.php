<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tagging;

use DragonOfMercy\PhpPdf\Outline\LinkAnnotation;

/**
 * A structure-element leaf that references a link annotation via an /OBJR
 * object reference (PDF/UA 7.18). Pairs the owning annotation with the page it
 * sits on and its 0-based tagged-link ordinal (used to derive the annotation's
 * /StructParent key at emit time).
 *
 * @internal
 */
final readonly class ObjrRef
{
    public function __construct(
        public LinkAnnotation $annotation,
        public int $pageIndex,
        public int $structParentTagIndex,
    ) {}
}
