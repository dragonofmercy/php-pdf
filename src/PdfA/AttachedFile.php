<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\PdfA;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * One file embedded in the document (PDF/A-3 associated file / generic
 * attachment). modDate is passed in so serialization is deterministic.
 */
final readonly class AttachedFile
{
    public function __construct(
        public string $name,
        public string $bytes,
        public AFRelationship $relationship,
        public string $mime,
        public ?string $description,
        public \DateTimeImmutable $modDate,
    ) {
        if ($this->name === '') {
            throw new PdfException('Attached file name cannot be empty');
        }
        if ($this->mime === '') {
            throw new PdfException("Attached file '{$this->name}' must have a non-empty MIME type");
        }
    }

    /**
     * The MIME type as a PDF name token body (the EmbeddedFile /Subtype): the
     * solidus is written as its #-escape, e.g. text/xml -> text#2Fxml.
     */
    public function subtypeName(): string
    {
        return str_replace('/', '#2F', $this->mime);
    }
}
