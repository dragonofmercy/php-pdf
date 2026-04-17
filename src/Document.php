<?php

declare(strict_types=1);

namespace PhpPdf;

use PhpPdf\Document\Metadata;
use PhpPdf\Exception\PdfException;
use PhpPdf\Writer\Object\Dictionary;
use PhpPdf\Writer\Object\IndirectObject;
use PhpPdf\Writer\Object\Name;
use PhpPdf\Writer\Object\PdfArray;
use PhpPdf\Writer\Object\PdfNumber;
use PhpPdf\Writer\Object\PdfReference;
use PhpPdf\Writer\PdfWriter;

/**
 * Public entry point for building PDF documents. Phase 0 only supports
 * adding empty A4-portrait pages — no content, no metadata, no fonts.
 */
final class Document
{
    private const int CATALOG_OBJECT = 1;
    private const int PAGES_OBJECT = 2;
    private const int FIRST_PAGE_OBJECT = 3;

    /** A4 portrait in PDF user units (1 unit = 1/72 inch). */
    private const float A4_WIDTH = 595.28;
    private const float A4_HEIGHT = 841.89;

    private int $pageCount = 0;
    private ?Metadata $metadata = null;

    public function addPage(): void
    {
        $this->pageCount++;
    }

    public function metadata(): Metadata
    {
        return $this->metadata ??= new Metadata();
    }

    public function output(): string
    {
        if ($this->pageCount === 0) {
            throw new PdfException('Document has no pages');
        }

        $pagesRef = PdfReference::to(self::PAGES_OBJECT, 0);

        $catalog = IndirectObject::of(
            self::CATALOG_OBJECT,
            0,
            Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Catalog'))
                ->withEntry(Name::of('Pages'), $pagesRef),
        );

        $pageRefs = [];
        $pageObjects = [];
        for ($i = 0; $i < $this->pageCount; $i++) {
            $pageObjectNumber = self::FIRST_PAGE_OBJECT + $i;
            $pageRefs[] = PdfReference::to($pageObjectNumber, 0);
            $pageObjects[] = IndirectObject::of(
                $pageObjectNumber,
                0,
                Dictionary::empty()
                    ->withEntry(Name::of('Type'), Name::of('Page'))
                    ->withEntry(Name::of('Parent'), $pagesRef)
                    ->withEntry(Name::of('MediaBox'), PdfArray::of(
                        PdfNumber::ofInt(0),
                        PdfNumber::ofInt(0),
                        PdfNumber::ofFloat(self::A4_WIDTH),
                        PdfNumber::ofFloat(self::A4_HEIGHT),
                    )),
            );
        }

        $pages = IndirectObject::of(
            self::PAGES_OBJECT,
            0,
            Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Pages'))
                ->withEntry(Name::of('Kids'), PdfArray::of(...$pageRefs))
                ->withEntry(Name::of('Count'), PdfNumber::ofInt($this->pageCount)),
        );

        $objects = [$catalog, $pages, ...$pageObjects];
        return (new PdfWriter())->write($objects, $catalog->reference());
    }

    public function save(string $path): void
    {
        $bytes = $this->output();
        $result = @file_put_contents($path, $bytes);
        if ($result === false) {
            throw new PdfException("Failed to write PDF to {$path}");
        }
    }
}
