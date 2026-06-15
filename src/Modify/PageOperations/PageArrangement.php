<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Modify\PageOperations;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Validated page arrangement: turns deletion / reorder requests (1-based page
 * numbers) into the surviving page object numbers in final order and the set of
 * deleted page object numbers. Deletions define the surviving set; an optional
 * reorder must be an exact permutation of the surviving pages.
 *
 * @internal
 */
final readonly class PageArrangement
{
    /** @var list<int> surviving page object numbers, final order */
    private array $finalOrder;

    /** @var list<int> deleted page object numbers */
    private array $deleted;

    /**
     * @param list<int> $originalObjectNumbers page object numbers, index 0 = page 1
     * @param list<int> $deletedPageNumbers 1-based pages to delete (already de-duplicated by the caller)
     * @param ?list<int> $reorderedPageOrder null = keep order; else a permutation of surviving 1-based numbers
     */
    public function __construct(array $originalObjectNumbers, array $deletedPageNumbers, ?array $reorderedPageOrder)
    {
        $pageCount = count($originalObjectNumbers);

        $deletedSet = [];
        foreach ($deletedPageNumbers as $n) {
            if ($n < 1 || $n > $pageCount) {
                throw new PdfException("Cannot delete page {$n}: document has {$pageCount} pages");
            }
            $deletedSet[$n] = true;
        }
        if (count($deletedSet) >= $pageCount) {
            throw new PdfException('Cannot delete every page: a PDF must keep at least one page');
        }

        $survivingNumbers = [];
        for ($n = 1; $n <= $pageCount; $n++) {
            if (!isset($deletedSet[$n])) {
                $survivingNumbers[] = $n;
            }
        }

        if ($reorderedPageOrder !== null) {
            $survivingNumbers = $this->applyReorder($survivingNumbers, $reorderedPageOrder, $deletedSet, $pageCount);
        }

        $this->finalOrder = array_map(static fn (int $n): int => $originalObjectNumbers[$n - 1], $survivingNumbers);
        $deleted = [];
        foreach (array_keys($deletedSet) as $n) {
            $deleted[] = $originalObjectNumbers[$n - 1];
        }
        $this->deleted = $deleted;
    }

    /** @return list<int> */
    public function finalOrder(): array
    {
        return $this->finalOrder;
    }

    /** @return list<int> */
    public function deletedObjectNumbers(): array
    {
        return $this->deleted;
    }

    /**
     * @param list<int> $surviving surviving 1-based numbers, original order
     * @param list<int> $order requested order (1-based)
     * @param array<int, true> $deletedSet
     * @return list<int>
     */
    private function applyReorder(array $surviving, array $order, array $deletedSet, int $pageCount): array
    {
        $survivingSet = array_fill_keys($surviving, true);
        $seen = [];
        foreach ($order as $n) {
            if ($n < 1 || $n > $pageCount) {
                throw new PdfException("reorderPages references page {$n} which does not exist (document has {$pageCount} pages)");
            }
            if (isset($deletedSet[$n])) {
                throw new PdfException("reorderPages references page {$n} which was deleted");
            }
            $seen[$n] = true;
        }
        if (count($seen) !== count($survivingSet) || array_diff_key($survivingSet, $seen) !== []) {
            throw new PdfException('reorderPages must list every surviving page exactly once');
        }
        return $order;
    }
}
