<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Ltv;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\Stream;

/**
 * Emits the indirect objects of a Document Security Store: one raw stream per
 * certificate and per CRL, plus a /DSS dictionary referencing them through
 * /Certs and /CRLs arrays. Global DSS only (no per-signature /VRI).
 *
 * @internal
 */
final readonly class DssBuilder
{
    /**
     * @return array{objects: list<IndirectObject>, dssObjectNumber: int, nextObjectNumber: int}
     */
    public function build(ValidationMaterial $material, int $firstObjectNumber): array
    {
        $objects = [];
        $next = $firstObjectNumber;

        $certRefs = [];
        foreach ($material->certificates as $der) {
            $objects[] = IndirectObject::of($next, 0, Stream::of($der));
            $certRefs[] = PdfReference::to($next, 0);
            $next++;
        }

        $crlRefs = [];
        foreach ($material->crls as $der) {
            $objects[] = IndirectObject::of($next, 0, Stream::of($der));
            $crlRefs[] = PdfReference::to($next, 0);
            $next++;
        }

        $dssDict = Dictionary::empty()->withEntry(Name::of('Type'), Name::of('DSS'));
        if ($certRefs !== []) {
            $dssDict = $dssDict->withEntry(Name::of('Certs'), PdfArray::of(...$certRefs));
        }
        if ($crlRefs !== []) {
            $dssDict = $dssDict->withEntry(Name::of('CRLs'), PdfArray::of(...$crlRefs));
        }

        $dssObjectNumber = $next;
        $objects[] = IndirectObject::of($dssObjectNumber, 0, $dssDict);
        $next++;

        return [
            'objects' => $objects,
            'dssObjectNumber' => $dssObjectNumber,
            'nextObjectNumber' => $next,
        ];
    }
}
