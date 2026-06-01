<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Writer;

/**
 * Cross-reference table for an appended (incremental) revision: only the
 * changed and new object numbers are recorded, grouped into contiguous
 * subsections. Offsets are absolute into the whole file. Unlike XrefTable,
 * there is no free (object 0) head entry.
 *
 * @internal
 */
final class IncrementalXref
{
    /** @var array<int, int> object number => absolute byte offset */
    private array $offsets = [];

    public function recordOffset(int $objectNumber, int $offset): void
    {
        $this->offsets[$objectNumber] = $offset;
    }

    public function toBytes(): string
    {
        $offsets = $this->offsets;
        ksort($offsets);

        $out = "xref\n";
        /** @var list<array{start: int, lines: list<string>}> $subsections */
        $subsections = [];
        /** @var array{start: int, lines: list<string>}|null $current */
        $current = null;
        $expected = null;
        foreach ($offsets as $objNum => $offset) {
            $line = sprintf("%010d 00000 n \n", $offset);
            if ($current === null || $objNum !== $expected) {
                if ($current !== null) {
                    $subsections[] = $current;
                }
                $current = ['start' => $objNum, 'lines' => [$line]];
            } else {
                $current['lines'][] = $line;
            }
            $expected = $objNum + 1;
        }
        if ($current !== null) {
            $subsections[] = $current;
        }

        foreach ($subsections as $sub) {
            $out .= $sub['start'] . ' ' . count($sub['lines']) . "\n";
            $out .= implode('', $sub['lines']);
        }
        return $out;
    }
}
