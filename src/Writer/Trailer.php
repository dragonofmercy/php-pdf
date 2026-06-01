<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Writer;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;

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
        private ?int $prev = null,
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

        if ($this->prev !== null) {
            $dict = $dict->withEntry(Name::of('Prev'), PdfNumber::ofInt($this->prev));
        }

        return "trailer\n" . $dict->toBytes() . "\nstartxref\n" . $this->xrefOffset . "\n%%EOF\n";
    }
}
