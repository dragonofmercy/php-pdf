<?php

declare(strict_types=1);

namespace PhpPdf\Encryption;

use PhpPdf\Writer\Object\Dictionary;
use PhpPdf\Writer\Object\HexString;
use PhpPdf\Writer\Object\IndirectObject;
use PhpPdf\Writer\Object\Name;
use PhpPdf\Writer\Object\PdfArray;
use PhpPdf\Writer\Object\PdfNumber;
use PhpPdf\Writer\Object\PdfReference;
use PhpPdf\Writer\XrefTable;

/**
 * PdfWriter variant that applies ObjectTransformer to every IndirectObject
 * before serialization. Also emits /Encrypt and /ID in the trailer.
 *
 * @internal
 */
final class EncryptedPdfWriter
{
    private const string HEADER = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";

    /**
     * @param list<IndirectObject> $objects must be sorted by object number, contiguous from 1
     */
    public function write(
        array $objects,
        PdfReference $root,
        ?PdfReference $info,
        PdfReference $encrypt,
        string $documentId,
        ObjectTransformer $transformer,
    ): string {
        $xref = new XrefTable();
        $body = self::HEADER;

        foreach ($objects as $object) {
            $xref->recordOffset($object->objectNumber, strlen($body));
            $body .= $transformer->transform($object)->toBytes();
        }

        $xrefOffset = strlen($body);
        $body .= $xref->toBytes();

        $body .= $this->buildTrailer(
            size: $xref->size(),
            root: $root,
            info: $info,
            encrypt: $encrypt,
            documentId: $documentId,
            xrefOffset: $xrefOffset,
        );

        return $body;
    }

    private function buildTrailer(
        int $size,
        PdfReference $root,
        ?PdfReference $info,
        PdfReference $encrypt,
        string $documentId,
        int $xrefOffset,
    ): string {
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Size'), PdfNumber::ofInt($size))
            ->withEntry(Name::of('Root'), $root);
        if ($info !== null) {
            $dict = $dict->withEntry(Name::of('Info'), $info);
        }
        $dict = $dict->withEntry(Name::of('Encrypt'), $encrypt);
        $id = HexString::of($documentId);
        $dict = $dict->withEntry(Name::of('ID'), PdfArray::of($id, $id));

        return "trailer\n" . $dict->toBytes() . "\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
    }
}
