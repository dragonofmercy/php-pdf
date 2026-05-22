<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Writer;

/**
 * Mutable allocator for PDF indirect-object numbers.
 *
 * Single source of truth for the object-number counter that was previously a
 * scattered `$nextObjectNumber` local. Hands out monotonically increasing
 * numbers so extracted serialization units share one sequence.
 *
 * @internal
 */
final class PdfObjectAllocator
{
    private int $next;

    public function __construct(int $first)
    {
        $this->next = $first;
    }

    /** Return the current number, then advance by one. */
    public function next(): int
    {
        return $this->next++;
    }

    /**
     * Return the current number, then advance by $count. Use for objects that
     * occupy several consecutive numbers (e.g. an image plus its SMask).
     */
    public function reserve(int $count): int
    {
        $first = $this->next;
        $this->next += $count;
        return $first;
    }

    /** Current number without advancing (e.g. the AcroForm start position). */
    public function peek(): int
    {
        return $this->next;
    }
}
