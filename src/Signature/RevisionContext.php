<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;

/**
 * Snapshot of the revision-1 objects an incremental DocTimeStamp revision needs
 * to re-emit or extend: the catalog, the AcroForm (if any), the first page, the
 * highest object number, and the document /ID.
 *
 * @internal
 */
final readonly class RevisionContext
{
    public function __construct(
        public IndirectObject $catalog,
        public ?IndirectObject $acroForm,
        public IndirectObject $firstPage,
        public int $maxObjectNumber,
        public string $documentId,
    ) {}
}
