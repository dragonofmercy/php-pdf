<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

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
 * Builds the indirect objects for an incremental revision that adds a
 * /DocTimeStamp. The timestamp value dict is /V of an invisible /FT /Sig field
 * whose widget sits on the first page (Rect [0 0 0 0]). New objects get fresh
 * numbers above the prior max; the first page and the AcroForm are re-emitted
 * under their original numbers with the field threaded in, and the catalog is
 * re-emitted only when no AcroForm existed yet (to add /AcroForm).
 *
 * @internal
 */
final readonly class DocTimeStampRevisionBuilder
{
    /**
     * @return array{objects: list<IndirectObject>, size: int}
     */
    public function build(RevisionContext $ctx, int $maxSignatureBytes): array
    {
        $tsId = $ctx->maxObjectNumber + 1;        // /DocTimeStamp value dict
        $fieldId = $ctx->maxObjectNumber + 2;     // invisible /FT /Sig widget
        $acroFormId = $ctx->maxObjectNumber + 3;  // new AcroForm (standalone case only)
        $fieldRef = PdfReference::to($fieldId, 0);
        $size = ($ctx->acroForm === null ? $acroFormId : $fieldId) + 1;

        $objects = [];

        $objects[] = (new DocTimeStampDictionaryEmitter())->emit($maxSignatureBytes, $tsId);

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
            ->withEntry(Name::of('T'), PdfString::of('DocTimeStamp1'))
            ->withEntry(Name::of('V'), PdfReference::to($tsId, 0));
        $objects[] = IndirectObject::of($fieldId, 0, $fieldDict);

        if ($ctx->acroForm === null) {
            $acroFormDict = Dictionary::empty()
                ->withEntry(Name::of('Fields'), PdfArray::of($fieldRef))
                ->withEntry(Name::of('SigFlags'), PdfNumber::ofInt(3));
            $objects[] = IndirectObject::of($acroFormId, 0, $acroFormDict);

            $catalogDict = self::dictOf($ctx->catalog)
                ->withEntry(Name::of('AcroForm'), PdfReference::to($acroFormId, 0));
            $objects[] = IndirectObject::of($ctx->catalog->objectNumber, 0, $catalogDict);
        } else {
            $acroFormDict = self::dictOf($ctx->acroForm);
            $fields = self::arrayEntry($acroFormDict, 'Fields');
            $acroFormDict = $acroFormDict
                ->withEntry(Name::of('Fields'), PdfArray::of(...[...$fields, $fieldRef]))
                ->withEntry(Name::of('SigFlags'), PdfNumber::ofInt(3));
            $objects[] = IndirectObject::of($ctx->acroForm->objectNumber, 0, $acroFormDict);
        }

        $pageDict = self::dictOf($ctx->firstPage);
        $annots = self::arrayEntry($pageDict, 'Annots');
        $pageDict = $pageDict->withEntry(Name::of('Annots'), PdfArray::of(...[...$annots, $fieldRef]));
        $objects[] = IndirectObject::of($ctx->firstPage->objectNumber, 0, $pageDict);

        return ['objects' => $objects, 'size' => $size];
    }

    private static function dictOf(IndirectObject $obj): Dictionary
    {
        $payload = $obj->payload();
        if (!$payload instanceof Dictionary) {
            throw new PdfException('DocTimeStamp revision: expected a Dictionary payload to re-emit');
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
