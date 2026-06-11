<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use Closure;
use DragonOfMercy\PhpPdf\Writer\Object\CompressedStream;
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

            $catalogDict = $ctx->catalog->dictionaryPayload()
                ->withEntry(Name::of('AcroForm'), PdfReference::to($acroFormId, 0));
            $newCatalog = IndirectObject::of($ctx->catalog->objectNumber, 0, $catalogDict);
            $objects[] = $newCatalog;
        } else {
            $acroFormDict = $ctx->acroForm->dictionaryPayload();
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

        $pageDict = $ctx->firstPage->dictionaryPayload();
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

    /**
     * Re-emits an EXISTING terminal /FT /Sig field (its dict given) with /V set
     * to a freshly-built value dict, ensuring /AcroForm /SigFlags 3. No new field
     * or annotation is added (the widget already exists in the tree).
     *
     * @param Closure(int): IndirectObject $valueDictFactory
     * @return array{objects: list<IndirectObject>, size: int, context: RevisionContext}
     */
    public function buildReuse(RevisionContext $ctx, Closure $valueDictFactory, IndirectObject $existingField): array
    {
        $valueId = $ctx->maxObjectNumber + 1;
        $objects = [];
        $objects[] = $valueDictFactory($valueId);

        $fieldDict = $existingField->dictionaryPayload()
            ->withEntry(Name::of('V'), PdfReference::to($valueId, 0));
        $objects[] = IndirectObject::of($existingField->objectNumber, 0, $fieldDict);

        $maxObjectNumber = max($valueId, $existingField->objectNumber);
        $newCatalog = $ctx->catalog;
        $newAcroForm = $ctx->acroForm;
        if ($ctx->acroForm !== null) {
            $acroDict = $ctx->acroForm->dictionaryPayload()
                ->withEntry(Name::of('SigFlags'), PdfNumber::ofInt(3));
            $newAcroForm = IndirectObject::of($ctx->acroForm->objectNumber, 0, $acroDict);
            $objects[] = $newAcroForm;
            $maxObjectNumber = max($maxObjectNumber, $ctx->acroForm->objectNumber);
        }

        $context = new RevisionContext(
            catalog: $newCatalog,
            acroForm: $newAcroForm,
            firstPage: $ctx->firstPage,
            maxObjectNumber: $maxObjectNumber,
            documentId: $ctx->documentId,
        );

        return ['objects' => $objects, 'size' => $maxObjectNumber + 1, 'context' => $context];
    }

    /**
     * Creates a NEW visible /FT /Sig field on $targetPage: non-zero /Rect, an
     * /AP /N Form XObject rendering the caption with a self-contained Helvetica
     * font, threaded into the AcroForm /Fields and the page /Annots, /SigFlags 3.
     *
     * @param Closure(int): IndirectObject $valueDictFactory
     * @param list<float> $rect [llx, lly, urx, ury]
     * @return array{objects: list<IndirectObject>, size: int, context: RevisionContext}
     */
    public function buildVisible(
        RevisionContext $ctx,
        Closure $valueDictFactory,
        string $fieldName,
        IndirectObject $targetPage,
        array $rect,
        SignatureAppearance $appearance,
    ): array {
        $valueId = $ctx->maxObjectNumber + 1;
        $fontId = $ctx->maxObjectNumber + 2;
        $apId = $ctx->maxObjectNumber + 3;
        $fieldId = $ctx->maxObjectNumber + 4;
        $acroFormId = $ctx->maxObjectNumber + 5; // standalone case only
        $fieldRef = PdfReference::to($fieldId, 0);

        $objects = [];
        $objects[] = $valueDictFactory($valueId);

        $fontDict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Font'))
            ->withEntry(Name::of('Subtype'), Name::of('Type1'))
            ->withEntry(Name::of('BaseFont'), Name::of('Helvetica'));
        $objects[] = IndirectObject::of($fontId, 0, $fontDict);

        $appearanceBuilt = (new SignatureAppearanceBuilder())->build($appearance);
        $bbox = $appearanceBuilt['bbox'];
        $apDict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('XObject'))
            ->withEntry(Name::of('Subtype'), Name::of('Form'))
            ->withEntry(Name::of('BBox'), PdfArray::of(
                PdfNumber::ofFloat($bbox[0]),
                PdfNumber::ofFloat($bbox[1]),
                PdfNumber::ofFloat($bbox[2]),
                PdfNumber::ofFloat($bbox[3]),
            ))
            ->withEntry(Name::of('Resources'), Dictionary::empty()
                ->withEntry(Name::of('Font'),
                    Dictionary::empty()->withEntry(Name::of('Helv'), PdfReference::to($fontId, 0))));
        $objects[] = IndirectObject::of($apId, 0, CompressedStream::of($appearanceBuilt['content'], $apDict));

        $fieldDict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Annot'))
            ->withEntry(Name::of('Subtype'), Name::of('Widget'))
            ->withEntry(Name::of('FT'), Name::of('Sig'))
            ->withEntry(Name::of('Rect'), PdfArray::of(
                PdfNumber::ofFloat($rect[0]),
                PdfNumber::ofFloat($rect[1]),
                PdfNumber::ofFloat($rect[2]),
                PdfNumber::ofFloat($rect[3]),
            ))
            ->withEntry(Name::of('T'), PdfString::of($fieldName))
            ->withEntry(Name::of('V'), PdfReference::to($valueId, 0))
            ->withEntry(Name::of('AP'), Dictionary::empty()
                ->withEntry(Name::of('N'), PdfReference::to($apId, 0)))
            ->withEntry(Name::of('F'), PdfNumber::ofInt(4));
        $objects[] = IndirectObject::of($fieldId, 0, $fieldDict);

        if ($ctx->acroForm === null) {
            $acroFormDict = Dictionary::empty()
                ->withEntry(Name::of('Fields'), PdfArray::of($fieldRef))
                ->withEntry(Name::of('SigFlags'), PdfNumber::ofInt(3));
            $newAcroForm = IndirectObject::of($acroFormId, 0, $acroFormDict);
            $objects[] = $newAcroForm;

            $catalogDict = $ctx->catalog->dictionaryPayload()
                ->withEntry(Name::of('AcroForm'), PdfReference::to($acroFormId, 0));
            $newCatalog = IndirectObject::of($ctx->catalog->objectNumber, 0, $catalogDict);
            $objects[] = $newCatalog;
            $maxObjectNumber = $acroFormId;
        } else {
            $acroFormDict = $ctx->acroForm->dictionaryPayload();
            $fields = self::arrayEntry($acroFormDict, 'Fields');
            $acroFormDict = $acroFormDict
                ->withEntry(Name::of('Fields'), PdfArray::of(...[...$fields, $fieldRef]))
                ->withEntry(Name::of('SigFlags'), PdfNumber::ofInt(3));
            $newAcroForm = IndirectObject::of($ctx->acroForm->objectNumber, 0, $acroFormDict);
            $objects[] = $newAcroForm;
            $newCatalog = $ctx->catalog;
            $maxObjectNumber = max($fieldId, $ctx->acroForm->objectNumber);
        }

        $pageDict = $targetPage->dictionaryPayload();
        $annots = self::arrayEntry($pageDict, 'Annots');
        $pageDict = $pageDict->withEntry(Name::of('Annots'), PdfArray::of(...[...$annots, $fieldRef]));
        $newPage = IndirectObject::of($targetPage->objectNumber, 0, $pageDict);
        $objects[] = $newPage;
        $maxObjectNumber = max($maxObjectNumber, $targetPage->objectNumber);

        $newFirstPage = $newPage->objectNumber === $ctx->firstPage->objectNumber ? $newPage : $ctx->firstPage;

        $context = new RevisionContext(
            catalog: $newCatalog,
            acroForm: $newAcroForm,
            firstPage: $newFirstPage,
            maxObjectNumber: $maxObjectNumber,
            documentId: $ctx->documentId,
        );

        return ['objects' => $objects, 'size' => $maxObjectNumber + 1, 'context' => $context];
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
