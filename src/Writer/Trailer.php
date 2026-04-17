<?php

declare(strict_types=1);

namespace PhpPdf\Writer;

use PhpPdf\Writer\Object\Dictionary;
use PhpPdf\Writer\Object\HexString;
use PhpPdf\Writer\Object\Name;
use PhpPdf\Writer\Object\PdfArray;
use PhpPdf\Writer\Object\PdfNumber;
use PhpPdf\Writer\Object\PdfReference;

/**
 * PDF file trailer (PDF 1.7 §7.5.5).
 *
 * @internal
 */
final readonly class Trailer
{
    public function __construct(
        private int $size,
        private PdfReference $root,
        private int $xrefOffset,
        private ?PdfReference $info = null,
        private ?string $documentId = null,
    ) {}

    public function toBytes(): string
    {
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Size'), PdfNumber::ofInt($this->size))
            ->withEntry(Name::of('Root'), $this->root);

        if ($this->info !== null) {
            $dict = $dict->withEntry(Name::of('Info'), $this->info);
        }

        if ($this->documentId !== null) {
            $id = HexString::of($this->documentId);
            $dict = $dict->withEntry(Name::of('ID'), PdfArray::of($id, $id));
        }

        return "trailer\n" . $dict->toBytes() . "\nstartxref\n" . $this->xrefOffset . "\n%%EOF\n";
    }
}
