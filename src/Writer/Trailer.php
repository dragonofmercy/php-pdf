<?php

declare(strict_types=1);

namespace PhpPdf\Writer;

use PhpPdf\Writer\Object\Dictionary;
use PhpPdf\Writer\Object\Name;
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
    ) {}

    public function toBytes(): string
    {
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Size'), PdfNumber::ofInt($this->size))
            ->withEntry(Name::of('Root'), $this->root);
        return "trailer\n" . $dict->toBytes() . "\nstartxref\n" . $this->xrefOffset . "\n%%EOF\n";
    }
}
