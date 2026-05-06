<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Document;

use DateTimeImmutable;

/**
 * Fluent builder for PDF document metadata. Each setter returns $this.
 * Public properties can be read directly for serialization by Document
 * and XmpWriter.
 */
final class Metadata
{
    public ?string $title = null;
    public ?string $author = null;
    public ?string $subject = null;
    public ?string $keywords = null;
    public ?string $creator = null;
    public ?string $producer = null;
    public ?DateTimeImmutable $creationDate = null;
    public ?DateTimeImmutable $modDate = null;
    public ?bool $trapped = null;
    public ?string $documentId = null;

    public function title(string $value): self
    {
        $this->title = $value;
        return $this;
    }

    public function author(string $value): self
    {
        $this->author = $value;
        return $this;
    }

    public function subject(string $value): self
    {
        $this->subject = $value;
        return $this;
    }

    public function keywords(string $value): self
    {
        $this->keywords = $value;
        return $this;
    }

    public function creator(string $value): self
    {
        $this->creator = $value;
        return $this;
    }

    public function producer(string $value): self
    {
        $this->producer = $value;
        return $this;
    }

    public function creationDate(DateTimeImmutable $value): self
    {
        $this->creationDate = $value;
        return $this;
    }

    public function modDate(DateTimeImmutable $value): self
    {
        $this->modDate = $value;
        return $this;
    }

    public function trapped(bool $value): self
    {
        $this->trapped = $value;
        return $this;
    }

    public function documentId(string $hexId): self
    {
        $this->documentId = $hexId;
        return $this;
    }
}
