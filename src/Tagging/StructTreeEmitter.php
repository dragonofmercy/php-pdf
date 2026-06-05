<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tagging;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\TextString;

/**
 * Serializes an accumulated StructureTree into PDF indirect objects:
 * a StructTreeRoot, one StructElem per node, and a ParentTree number tree.
 *
 * Object numbering, starting at $startObjectNumber:
 *   start       -> StructTreeRoot
 *   start + 1   -> ParentTree number-tree dictionary
 *   start + 2.. -> StructElem objects (Document first, then depth-first)
 *
 * @internal
 */
final class StructTreeEmitter
{
    /** @var \SplObjectStorage<StructElem, int> object number assigned to each StructElem */
    private \SplObjectStorage $numbers;

    /** @var \SplObjectStorage<StructElem, StructElem> parent element of each StructElem (root absent) */
    private \SplObjectStorage $parents;

    /** @var list<StructElem> elements in numbering order (Document first, then depth-first) */
    private array $order = [];

    private int $nextElemNumber = 0;

    /**
     * @param list<PdfReference> $pageRefs the page object references in page order
     */
    public function emit(StructureTree $tree, array $pageRefs, int $startObjectNumber): StructTreeResult
    {
        $rootRefNumber = $startObjectNumber;
        $parentTreeNumber = $startObjectNumber + 1;

        $this->numbers = new \SplObjectStorage();
        $this->parents = new \SplObjectStorage();
        $this->order = [];
        $this->nextElemNumber = $startObjectNumber + 2;
        $this->assignNumbers($tree->root(), null);

        // Per page: array indexed by MCID -> owning StructElem reference.
        /** @var array<int, array<int, PdfReference>> $parentTree */
        $parentTree = [];
        foreach (array_keys($pageRefs) as $pageIndex) {
            $parentTree[$pageIndex] = [];
        }

        $elemObjects = [];
        foreach ($this->order as $elem) {
            $elemNumber = $this->numberOf($elem);
            $kids = [];
            foreach ($elem->children() as $child) {
                if ($child instanceof StructElem) {
                    $kids[] = PdfReference::to($this->numberOf($child), 0);
                } elseif ($child instanceof MarkedContentRef) {
                    $kids[] = PdfNumber::ofInt($child->mcid);
                    $parentTree[$child->pageIndex][$child->mcid] = PdfReference::to($elemNumber, 0);
                }
            }

            $dict = Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('StructElem'))
                ->withEntry(Name::of('S'), Name::of($elem->type()->value));

            // Parent: the Document root's parent is the StructTreeRoot; every
            // other element's parent is its owning StructElem.
            $parentNumber = $elem === $tree->root()
                ? $rootRefNumber
                : $this->numberOf($this->parents[$elem]);
            $dict = $dict->withEntry(Name::of('P'), PdfReference::to($parentNumber, 0));

            // /Pg: only on elements that DIRECTLY hold a marked-content leaf,
            // using that leaf's page. Pure container elements (Document, Table,
            // TR, L, LI...) get no /Pg (it is optional and most useful on
            // content-bearing elements).
            $pageIndex = $this->directLeafPageIndex($elem);
            if ($pageIndex !== null && isset($pageRefs[$pageIndex])) {
                $dict = $dict->withEntry(Name::of('Pg'), $pageRefs[$pageIndex]);
            }

            // /Alt: human-readable alternate text (e.g. figure description),
            // encoded as a UTF-16BE text string.
            $alt = $elem->alt();
            if ($alt !== null) {
                $dict = $dict->withEntry(Name::of('Alt'), TextString::of($alt));
            }

            // /A <</O /Table /Scope /Column|Row>>: header-cell scope on a TH.
            $scope = $elem->scope();
            if ($scope !== null) {
                $dict = $dict->withEntry(
                    Name::of('A'),
                    Dictionary::empty()
                        ->withEntry(Name::of('O'), Name::of('Table'))
                        ->withEntry(Name::of('Scope'), Name::of($scope->value)),
                );
            }

            $dict = $dict->withEntry(Name::of('K'), $this->kidsValue($kids));
            $elemObjects[] = IndirectObject::of($elemNumber, 0, $dict);
        }

        // ParentTree number tree: /Nums [ key [refs...] key [refs...] ... ].
        $nums = [];
        foreach ($parentTree as $pageIndex => $byMcid) {
            ksort($byMcid);
            /** @var list<PdfReference> $arr */
            $arr = [];
            $max = $byMcid === [] ? -1 : max(array_keys($byMcid));
            for ($i = 0; $i <= $max; $i++) {
                // Every minted MCID is recorded, so a gap is a programming error.
                $arr[] = $byMcid[$i] ?? throw new PdfException("ParentTree gap at page {$pageIndex}, mcid {$i}");
            }
            $nums[] = PdfNumber::ofInt($pageIndex);
            $nums[] = PdfArray::of(...$arr);
        }
        $parentTreeObject = IndirectObject::of(
            $parentTreeNumber,
            0,
            Dictionary::empty()->withEntry(Name::of('Nums'), PdfArray::of(...$nums)),
        );

        // StructTreeRoot: /K is the Document element (single ref).
        $rootObject = IndirectObject::of(
            $rootRefNumber,
            0,
            Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('StructTreeRoot'))
                ->withEntry(Name::of('K'), PdfReference::to($this->numberOf($tree->root()), 0))
                ->withEntry(Name::of('ParentTree'), PdfReference::to($parentTreeNumber, 0))
                ->withEntry(Name::of('RoleMap'), Dictionary::empty()),
        );

        return new StructTreeResult(
            objects: [$rootObject, $parentTreeObject, ...$elemObjects],
            structTreeRootRef: PdfReference::to($rootRefNumber, 0),
        );
    }

    /**
     * Assigns object numbers depth-first (Document first) and records each
     * element's parent, populating $this->numbers, $this->parents, $this->order.
     */
    private function assignNumbers(StructElem $elem, ?StructElem $parent): void
    {
        $this->numbers[$elem] = $this->nextElemNumber++;
        if ($parent !== null) {
            $this->parents[$elem] = $parent;
        }
        $this->order[] = $elem;
        foreach ($elem->children() as $child) {
            if ($child instanceof StructElem) {
                $this->assignNumbers($child, $elem);
            }
        }
    }

    private function numberOf(StructElem $elem): int
    {
        return $this->numbers[$elem];
    }

    /** @param list<PdfObject> $kids */
    private function kidsValue(array $kids): PdfObject
    {
        if (count($kids) === 1) {
            return $kids[0];
        }
        return PdfArray::of(...$kids);
    }

    /**
     * The page of this element's first DIRECT marked-content leaf, or null when
     * the element holds no leaf child (a pure container). Used for /Pg, which is
     * emitted only on direct leaf-holders.
     */
    private function directLeafPageIndex(StructElem $elem): ?int
    {
        foreach ($elem->children() as $child) {
            if ($child instanceof MarkedContentRef) {
                return $child->pageIndex;
            }
        }
        return null;
    }
}
