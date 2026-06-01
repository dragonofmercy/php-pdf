<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Writer;

use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;

/**
 * Appends an incremental revision to an already-finalized PDF. The prior bytes
 * are preserved verbatim; new and re-emitted indirect objects are written after
 * them, followed by a subsectioned xref and a trailer carrying /Prev pointing
 * at the previous cross-reference section.
 *
 * @internal
 */
final class IncrementalWriter
{
    /**
     * @param list<IndirectObject> $newObjects new and re-emitted objects for this revision
     */
    public function append(
        string $priorBytes,
        array $newObjects,
        PdfReference $root,
        string $documentId,
        int $prevStartxref,
        int $size,
    ): string {
        $body = $priorBytes;
        $xref = new IncrementalXref();
        foreach ($newObjects as $object) {
            $xref->recordOffset($object->objectNumber, strlen($body));
            $body .= $object->toBytes();
        }

        $xrefOffset = strlen($body);
        $body .= $xref->toBytes();

        $trailer = new Trailer(
            size: $size,
            root: $root,
            xrefOffset: $xrefOffset,
            info: null,
            documentId: $documentId,
            prev: $prevStartxref,
        );
        $body .= $trailer->toBytes();

        return $body;
    }
}
