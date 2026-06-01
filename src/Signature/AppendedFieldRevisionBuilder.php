<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use Closure;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;

/**
 * Builds the indirect objects for one incremental revision that adds a
 * signature field whose /V is a value dict (a /Sig or /DocTimeStamp). The value
 * dict is produced by the supplied factory; an invisible /FT /Sig widget on the
 * first page references it; the AcroForm and first page are re-emitted under
 * their original numbers with the field threaded in (and the catalog re-emitted
 * to add /AcroForm only when none existed). Returns the new objects, the new
 * /Size, and the context evolved so the next appended revision builds on top.
 *
 * @internal
 */
final readonly class AppendedFieldRevisionBuilder
{
    /**
     * @param Closure(int): IndirectObject $valueDictFactory builds the /V value dict at the given object number
     * @return array{objects: list<IndirectObject>, size: int, context: RevisionContext}
     */
    public function build(RevisionContext $ctx, Closure $valueDictFactory, string $fieldName): array
    {
        $valueId = $ctx->maxObjectNumber + 1;
        $fieldId = $ctx->maxObjectNumber + 2;
        $acroFormId = $ctx->maxObjectNumber + 3; // standalone case only
        $fieldRef = PdfReference::to($fieldId, 0);

        $objects = [];
        $objects[] = $valueDictFactory($valueId);

        $fieldDict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Annot'))
            ->withEntry(Name::of('Subtype'), Name::of('Widget'))
            ->withEntry(Name::of('FT'), Name::of('Sig'))
            ->withEntry(Name::of('Rect'), PdfArray::of(
                PdfNumber::ofInt(0),
                PdfNumber::ofInt(0),
                PdfNumber::ofInt(0),
                PdfNumber::ofInt(0),
            ))
            ->withEntry(Name::of('T'), PdfString::of($fieldName))
            ->withEntry(Name::of('V'), PdfReference::to($valueId, 0));
        $objects[] = IndirectObject::of($fieldId, 0, $fieldDict);

        if ($ctx->acroForm === null) {
            $acroFormDict = Dictionary::empty()
                ->withEntry(Name::of('Fields'), PdfArray::of($fieldRef))
                ->withEntry(Name::of('SigFlags'), PdfNumber::ofInt(3));
            $newAcroForm = IndirectObject::of($acroFormId, 0, $acroFormDict);
            $objects[] = $newAcroForm;

            $catalogDict = self::dictOf($ctx->catalog)
                ->withEntry(Name::of('AcroForm'), PdfReference::to($acroFormId, 0));
            $newCatalog = IndirectObject::of($ctx->catalog->objectNumber, 0, $catalogDict);
            $objects[] = $newCatalog;
        } else {
            $acroFormDict = self::dictOf($ctx->acroForm);
            $fields = self::arrayEntry($acroFormDict, 'Fields');
            $acroFormDict = $acroFormDict
                ->withEntry(Name::of('Fields'), PdfArray::of(...[...$fields, $fieldRef]))
                ->withEntry(Name::of('SigFlags'), PdfNumber::ofInt(3));
            $newAcroForm = IndirectObject::of($ctx->acroForm->objectNumber, 0, $acroFormDict);
            $objects[] = $newAcroForm;
            $newCatalog = $ctx->catalog;
        }

        // The standalone branch also allocates the new AcroForm object; the
        // combined branch re-emits the existing one in place.
        $maxObjectNumber = $ctx->acroForm === null ? $acroFormId : $fieldId;

        $pageDict = self::dictOf($ctx->firstPage);
        $annots = self::arrayEntry($pageDict, 'Annots');
        $pageDict = $pageDict->withEntry(Name::of('Annots'), PdfArray::of(...[...$annots, $fieldRef]));
        $newPage = IndirectObject::of($ctx->firstPage->objectNumber, 0, $pageDict);
        $objects[] = $newPage;

        $context = new RevisionContext(
            catalog: $newCatalog,
            acroForm: $newAcroForm,
            firstPage: $newPage,
            maxObjectNumber: $maxObjectNumber,
            documentId: $ctx->documentId,
        );

        return ['objects' => $objects, 'size' => $maxObjectNumber + 1, 'context' => $context];
    }

    private static function dictOf(IndirectObject $obj): Dictionary
    {
        $payload = $obj->payload();
        if (!$payload instanceof Dictionary) {
            throw new PdfException('Appended revision: expected a Dictionary payload to re-emit');
        }
        return $payload;
    }

    /**
     * @return list<PdfObject>
     */
    private static function arrayEntry(Dictionary $dict, string $key): array
    {
        $value = $dict->get(Name::of($key));
        return $value instanceof PdfArray ? $value->elements() : [];
    }
}
