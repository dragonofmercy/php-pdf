<?php

declare(strict_types=1);

namespace PhpPdf\Writer;

/**
 * PDF cross-reference table (PDF 1.7 §7.5.4). Stateful: callers invoke
 * recordOffset() as indirect objects are emitted, then toBytes() to obtain
 * the xref section. Phase 0 assumes contiguous object numbers starting at 1
 * and no deletions — there is always a single subsection 0..N.
 *
 * @internal
 */
final class XrefTable
{
    /** @var array<int, int> object number => byte offset */
    private array $offsets = [];

    public function recordOffset(int $objectNumber, int $offset): void
    {
        $this->offsets[$objectNumber] = $offset;
    }

    public function size(): int
    {
        return count($this->offsets) + 1;
    }

    public function toBytes(): string
    {
        $size = $this->size();
        $out = "xref\n0 $size\n0000000000 65535 f \n";
        ksort($this->offsets);
        foreach ($this->offsets as $offset) {
            $out .= sprintf("%010d 00000 n \n", $offset);
        }
        return $out;
    }
}
