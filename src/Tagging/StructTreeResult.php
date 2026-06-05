<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tagging;

use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;

/**
 * Output of StructTreeEmitter: the new indirect objects, the StructTreeRoot
 * reference for the catalog, and the per-page /StructParents values.
 */
final readonly class StructTreeResult
{
    /**
     * @param list<IndirectObject> $objects
     * @param array<int, int> $pageStructParents pageIndex => /StructParents value
     */
    public function __construct(
        public array $objects,
        public PdfReference $structTreeRootRef,
        public array $pageStructParents,
    ) {}
}
