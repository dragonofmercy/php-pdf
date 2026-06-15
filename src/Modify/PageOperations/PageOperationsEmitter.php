<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Modify\PageOperations;

use DragonOfMercy\PhpPdf\Document\PageSetEmitter;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\PdfObjectAllocator;

/**
 * Coordinates a page delete/reorder: validates the arrangement, emits any
 * appended pages, rewrites the page tree, prunes dangling outline / named-dest /
 * link references, and reports whether the outline tree emptied (so the caller
 * drops /Outlines from the catalog). Returns the overridden objects and the next
 * free object number.
 *
 * @internal
 */
final readonly class PageOperationsEmitter
{
    /**
     * @param list<int> $deletedPageNumbers
     * @param ?list<int> $reorderedPageOrder
     * @param list<Page> $appendedPages
     */
    public function __construct(
        private PdfReader $reader,
        private array $deletedPageNumbers,
        private ?array $reorderedPageOrder,
        private array $appendedPages,
        private PageSetEmitter $pageEmitter,
    ) {}

    /**
     * @return array{objects: list<IndirectObject>, outlinesEmptied: bool, nextNumber: int}
     */
    public function emit(int $nextNumber): array
    {
        $pagesRootRef = $this->reader->catalog()->get(Name::of('Pages'));
        if (!$pagesRootRef instanceof PdfReference) {
            throw new PdfException('The opened PDF has no indirect /Pages reference');
        }

        $pageCount = $this->reader->pageCount();
        $originalObjNums = [];
        /** @var array<int, PageRecord> $recordByObj */
        $recordByObj = [];
        for ($i = 1; $i <= $pageCount; $i++) {
            $p = $this->reader->page($i);
            if ($p->objectNumber === null) {
                throw new PdfException("Cannot delete/reorder pages: page {$i} is inlined and has no object number");
            }
            $originalObjNums[] = $p->objectNumber;
            $recordByObj[$p->objectNumber] = new PageRecord(
                $p->objectNumber,
                $p->dict,
                $p->mediaBox,
                $p->cropBox,
                $p->rotate,
                $p->resources,
            );
        }

        $arrangement = new PageArrangement($originalObjNums, $this->deletedPageNumbers, $this->reorderedPageOrder);
        $finalObjNums = $arrangement->finalOrder();
        $deleted = $arrangement->deletedObjectNumbers();

        /** @var list<PageRecord> $finalRecords */
        $finalRecords = array_map(static fn (int $n): PageRecord => $recordByObj[$n], $finalObjNums);

        $objects = [];

        $appendedRefs = [];
        if ($this->appendedPages !== []) {
            $allocator = new PdfObjectAllocator($nextNumber);
            $build = $this->pageEmitter->emit($this->appendedPages, $allocator, $pagesRootRef);
            $appendedObjNums = [];
            foreach ($build['pageRefs'] as $ref) {
                $appendedRefs[] = $ref;
                $appendedObjNums[$ref->objectNumber] = true;
            }
            foreach ($build['objects'] as $object) {
                $objects[] = $this->withRotateZeroOnPages($object, $appendedObjNums);
            }
            $nextNumber = $allocator->peek();
        }

        foreach ((new PageTreeRewriter())->rewrite($this->reader, $pagesRootRef, $finalRecords, $appendedRefs) as $o) {
            $objects[] = $o;
        }

        $outlineResult = (new OutlinePruner())->prune($this->reader, $deleted);
        foreach ($outlineResult->objects as $o) {
            $objects[] = $o;
        }
        foreach ((new NamedDestinationPruner())->prune($this->reader, $deleted) as $o) {
            $objects[] = $o;
        }
        foreach ((new LinkAnnotationPruner())->prune($this->reader, $finalObjNums, $deleted) as $o) {
            $objects[] = $o;
        }

        return ['objects' => $objects, 'outlinesEmptied' => $outlineResult->outlinesEmptied, 'nextNumber' => $nextNumber];
    }

    /** @param array<int, true> $pageObjectNumbers */
    private function withRotateZeroOnPages(IndirectObject $object, array $pageObjectNumbers): IndirectObject
    {
        if (!isset($pageObjectNumbers[$object->objectNumber])) {
            return $object;
        }
        return IndirectObject::of(
            $object->objectNumber,
            $object->generation,
            $object->dictionaryPayload()->withEntry(Name::of('Rotate'), PdfNumber::ofInt(0)),
        );
    }
}
