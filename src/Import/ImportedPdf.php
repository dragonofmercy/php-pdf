<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Import;

use DragonOfMercy\PhpPdf\Reader\PdfReader;

/**
 * A parsed source PDF bound to a destination Document for template import.
 * Parsing happens once at import time; page templates are cached per page
 * number so the same template placed on many pages is emitted once.
 */
final class ImportedPdf
{
    /** @var array<int, ImportedPageTemplate> */
    private array $templates = [];

    public function __construct(private readonly PdfReader $reader) {}

    public function pageCount(): int
    {
        return $this->reader->pageCount();
    }

    /** @param int $pageNumber 1-based */
    public function page(int $pageNumber): ImportedPageTemplate
    {
        return $this->templates[$pageNumber] ??= new ImportedPageTemplate(
            $this->reader,
            $this->reader->page($pageNumber),
            $pageNumber,
        );
    }
}
