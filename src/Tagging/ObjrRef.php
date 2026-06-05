<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tagging;

use DragonOfMercy\PhpPdf\Outline\LinkAnnotation;

/**
 * A structure-element leaf that references a link annotation via an /OBJR
 * object reference (PDF/UA 7.18). Pairs the owning annotation with the page it
 * sits on; the annotation itself carries the tagged-link ordinal used to derive
 * its /StructParent key at emit time (see {@see LinkAnnotation::structParentKey()}).
 *
 * @internal
 */
final readonly class ObjrRef
{
    public function __construct(
        public LinkAnnotation $annotation,
        public int $pageIndex,
    ) {}
}
