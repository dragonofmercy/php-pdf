<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Modify;

use DragonOfMercy\PhpPdf\Page;

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

    /** @var list<Page> */
    public array $pages = [];

    /** @var array<string, string|bool|list<string>> Pending field-value edits keyed by fully-qualified field name (last write wins). */
    public array $fieldEdits = [];

    /** When true, flatten value-bearing AcroForm fields at output(). */
    public bool $flatten = false;

    /** @var ?list<string> null = all value-bearing fields; a list = the named subset. */
    public ?array $flattenNames = null;

    /** @var list<int> 1-based page numbers queued for deletion (validated for range/permutation at output()). */
    public array $deletedPageNumbers = [];

    /** @var ?list<int> null = no reorder; a list = the desired order of the surviving pages by original 1-based number. */
    public ?array $reorderedPageOrder = null;

    public function hasMetadata(): bool
    {
        return $this->title !== null || $this->author !== null || $this->subject !== null
            || $this->keywords !== null || $this->creator !== null;
    }

    public function isEmpty(): bool
    {
        return !$this->hasMetadata() && $this->pages === [] && $this->fieldEdits === [] && !$this->flatten
            && $this->deletedPageNumbers === [] && $this->reorderedPageOrder === null;
    }
}
