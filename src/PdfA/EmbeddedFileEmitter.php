<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\PdfA;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use DragonOfMercy\PhpPdf\Writer\PdfDate;

/**
 * Builds the /Filespec and /EmbeddedFile stream objects for a set of attached
 * files. Two indirect objects per attachment (filespec then stream), numbered
 * sequentially from firstObjectNumber.
 *
 * @internal
 */
final class EmbeddedFileEmitter
{
    /**
     * @param list<AttachedFile> $attachments
     * @return array{objects: list<IndirectObject>, filespecRefs: list<PdfReference>}
     */
    public function emit(array $attachments, int $firstObjectNumber): array
    {
        $objects = [];
        $filespecRefs = [];
        $n = $firstObjectNumber;
        foreach ($attachments as $a) {
            $filespecNumber = $n;
            $streamNumber = $n + 1;
            $n += 2;

            $params = Dictionary::empty()
                ->withEntry(Name::of('Size'), PdfNumber::ofInt(strlen($a->bytes)))
                ->withEntry(Name::of('ModDate'), PdfString::of(PdfDate::format($a->modDate)))
                ->withEntry(Name::of('CheckSum'), HexString::of(md5($a->bytes)));

            $streamDict = Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('EmbeddedFile'))
                ->withEntry(Name::of('Subtype'), Name::of($a->mime))
                ->withEntry(Name::of('Params'), $params);
            $stream = IndirectObject::of($streamNumber, 0, new EmbeddedFileStream($streamDict, $a->bytes));

            $ef = Dictionary::empty()
                ->withEntry(Name::of('F'), PdfReference::to($streamNumber, 0))
                ->withEntry(Name::of('UF'), PdfReference::to($streamNumber, 0));
            $filespecDict = Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Filespec'))
                ->withEntry(Name::of('F'), PdfString::of($a->name))
                ->withEntry(Name::of('UF'), PdfString::of($a->name))
                ->withEntry(Name::of('AFRelationship'), Name::of($a->relationship->pdfName()))
                ->withEntry(Name::of('EF'), $ef);
            if ($a->description !== null) {
                $filespecDict = $filespecDict->withEntry(Name::of('Desc'), PdfString::of($a->description));
            }
            $filespec = IndirectObject::of($filespecNumber, 0, $filespecDict);

            $objects[] = $filespec;
            $objects[] = $stream;
            $filespecRefs[] = PdfReference::to($filespecNumber, 0);
        }
        return ['objects' => $objects, 'filespecRefs' => $filespecRefs];
    }
}
