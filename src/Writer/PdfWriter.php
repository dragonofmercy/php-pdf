<?php

declare(strict_types=1);

namespace PhpPdf\Writer;

use PhpPdf\Writer\Object\IndirectObject;
use PhpPdf\Writer\Object\PdfReference;

/**
 * Low-level PDF byte assembler. Stateless across calls — each write() builds
 * a fresh XrefTable and Trailer.
 *
 * @internal
 */
final class PdfWriter
{
    private const string HEADER = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";

    /**
     * @param list<IndirectObject> $objects must be sorted by object number, contiguous starting at 1
     */
    public function write(array $objects, PdfReference $root): string
    {
        $xref = new XrefTable();
        $body = self::HEADER;

        foreach ($objects as $object) {
            $xref->recordOffset($object->objectNumber, strlen($body));
            $body .= $object->toBytes();
        }

        $xrefOffset = strlen($body);
        $body .= $xref->toBytes();

        $trailer = new Trailer(size: $xref->size(), root: $root, xrefOffset: $xrefOffset);
        $body .= $trailer->toBytes();

        return $body;
    }
}
