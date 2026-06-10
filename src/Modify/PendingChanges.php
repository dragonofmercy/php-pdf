<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Modify;

/**
 * Mutable accumulator of pending modifications on an opened PDF.
 *
 * @internal
 */
final class PendingChanges
{
    public ?string $title = null;
    public ?string $author = null;
    public ?string $subject = null;
    public ?string $keywords = null;
    public ?string $creator = null;

    public function hasMetadata(): bool
    {
        return $this->title !== null || $this->author !== null || $this->subject !== null
            || $this->keywords !== null || $this->creator !== null;
    }

    public function isEmpty(): bool
    {
        return !$this->hasMetadata();
    }
}
