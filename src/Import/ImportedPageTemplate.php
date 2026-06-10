<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Import;

use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Reader\ReadPage;

/**
 * One page of an imported PDF, usable as a drawing template via
 * Page::template(). The natural size is the page's VISUAL size in points:
 * /Rotate 90 or 270 swaps width and height, because the template is emitted
 * upright (the rotation is baked into the Form XObject's /Matrix).
 */
final readonly class ImportedPageTemplate
{
    public function __construct(
        private PdfReader $reader,
        private ReadPage $page,
        private int $pageNumber,
    ) {}

    public function widthPt(): float
    {
        $box = $this->page->box();
        return $this->isSideways() ? $box[3] - $box[1] : $box[2] - $box[0];
    }

    public function heightPt(): float
    {
        $box = $this->page->box();
        return $this->isSideways() ? $box[2] - $box[0] : $box[3] - $box[1];
    }

    /** @internal */
    public function reader(): PdfReader
    {
        return $this->reader;
    }

    /** @internal */
    public function page(): ReadPage
    {
        return $this->page;
    }

    /** @internal */
    public function pageNumber(): int
    {
        return $this->pageNumber;
    }

    private function isSideways(): bool
    {
        return $this->page->rotate === 90 || $this->page->rotate === 270;
    }
}
