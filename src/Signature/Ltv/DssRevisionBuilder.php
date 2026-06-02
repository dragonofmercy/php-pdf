<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Ltv;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\RevisionContext;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;

/**
 * Builds one incremental revision that adds a Document Security Store: the
 * cert/CRL streams and /DSS dictionary from DssBuilder, plus the catalog
 * re-emitted under its own object number with a /DSS reference. Returns the
 * same {objects, size, context} shape as AppendedFieldRevisionBuilder so the
 * output loop handles both uniformly.
 *
 * @internal
 */
final readonly class DssRevisionBuilder
{
    /**
     * @return array{objects: list<IndirectObject>, size: int, context: RevisionContext}
     */
    public function build(RevisionContext $ctx, ValidationMaterial $material): array
    {
        $built = (new DssBuilder())->build($material, $ctx->maxObjectNumber + 1);
        $objects = $built['objects'];

        $catalogDict = $this->dictOf($ctx->catalog)
            ->withEntry(Name::of('DSS'), PdfReference::to($built['dssObjectNumber'], 0));
        $newCatalog = IndirectObject::of($ctx->catalog->objectNumber, 0, $catalogDict);
        $objects[] = $newCatalog;

        $maxObjectNumber = $built['dssObjectNumber'];
        $context = new RevisionContext(
            catalog: $newCatalog,
            acroForm: $ctx->acroForm,
            firstPage: $ctx->firstPage,
            maxObjectNumber: $maxObjectNumber,
            documentId: $ctx->documentId,
        );

        return ['objects' => $objects, 'size' => $maxObjectNumber + 1, 'context' => $context];
    }

    private function dictOf(IndirectObject $obj): Dictionary
    {
        $payload = $obj->payload();
        if (!$payload instanceof Dictionary) {
            throw new PdfException('DSS revision: expected a Dictionary catalog payload to re-emit');
        }
        return $payload;
    }
}
