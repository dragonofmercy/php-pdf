<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tagging;

use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;

/**
 * Output of StructTreeEmitter: the new indirect objects and the StructTreeRoot
 * reference for the catalog.
 */
final readonly class StructTreeResult
{
    /**
     * @param list<IndirectObject> $objects
     */
    public function __construct(
        public array $objects,
        public PdfReference $structTreeRootRef,
    ) {}
}
