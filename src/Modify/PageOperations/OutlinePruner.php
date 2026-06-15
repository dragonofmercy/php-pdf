<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Modify\PageOperations;

use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;

/**
 * Prunes the raw /Outlines tree of an opened PDF: removes every item whose
 * destination resolves to a deleted page, promoting that item's surviving
 * children into its parent. Re-emits only the changed objects.
 *
 * @internal
 */
final readonly class OutlinePruner
{
    /**
     * @param list<int> $deletedPageObjects object numbers of deleted pages
     */
    public function prune(PdfReader $reader, array $deletedPageObjects): OutlinePruneResult
    {
        if ($deletedPageObjects === []) {
            return new OutlinePruneResult([], false);
        }
        $deleted = array_fill_keys($deletedPageObjects, true);

        $outlinesRef = $reader->catalog()->get(Name::of('Outlines'));
        if (!$outlinesRef instanceof PdfReference) {
            return new OutlinePruneResult([], false);
        }
        $rootNum = $outlinesRef->objectNumber;
        $rootDict = $reader->resolve($outlinesRef);
        if (!$rootDict instanceof Dictionary) {
            return new OutlinePruneResult([], false);
        }

        $items = [];
        $rootChildren = $this->loadChildren($reader, $rootDict, $deleted, $items);

        $survivingRootChildren = $this->survivors($rootChildren, $items);

        if ($survivingRootChildren === []) {
            return new OutlinePruneResult([], true);
        }

        $objects = [];
        $this->reemitForest($survivingRootChildren, $rootNum, $items, $objects);

        $objects[] = IndirectObject::of(
            $rootNum,
            0,
            $rootDict
                ->withEntry(Name::of('First'), PdfReference::to($survivingRootChildren[0], 0))
                ->withEntry(Name::of('Last'), PdfReference::to($survivingRootChildren[count($survivingRootChildren) - 1], 0))
                ->withEntry(Name::of('Count'), PdfNumber::ofInt($this->countDescendants($survivingRootChildren, $items))),
        );

        return new OutlinePruneResult($objects, false);
    }

    /**
     * @param array<int, true> $deleted
     * @param array<int, array{dict: Dictionary, children: list<int>, deleted: bool}> $items
     * @return list<int>
     */
    private function loadChildren(PdfReader $reader, Dictionary $node, array $deleted, array &$items): array
    {
        $children = [];
        $cursor = $node->get(Name::of('First'));
        $guard = 0;
        while ($cursor instanceof PdfReference) {
            if (++$guard > 100000) {
                break;
            }
            $num = $cursor->objectNumber;
            $dict = $reader->resolve($cursor);
            if (!$dict instanceof Dictionary) {
                break;
            }
            $items[$num] = [
                'dict' => $dict,
                'children' => $this->loadChildren($reader, $dict, $deleted, $items),
                'deleted' => DestinationTarget::dictTargetsDeleted($dict, $reader, $deleted),
            ];
            $children[] = $num;
            $cursor = $dict->get(Name::of('Next'));
        }
        return $children;
    }

    /**
     * @param list<int> $children
     * @param array<int, array{dict: Dictionary, children: list<int>, deleted: bool}> $items
     * @return list<int>
     */
    private function survivors(array $children, array $items): array
    {
        $out = [];
        foreach ($children as $num) {
            $node = $items[$num];
            $childSurvivors = $this->survivors($node['children'], $items);
            if ($node['deleted']) {
                foreach ($childSurvivors as $promoted) {
                    $out[] = $promoted;
                }
            } else {
                $out[] = $num;
            }
        }
        return $out;
    }

    /**
     * @param list<int> $siblings surviving sibling object numbers in order
     * @param array<int, array{dict: Dictionary, children: list<int>, deleted: bool}> $items
     * @param list<IndirectObject> $objects
     */
    private function reemitForest(array $siblings, int $parentNum, array $items, array &$objects): void
    {
        $count = count($siblings);
        for ($i = 0; $i < $count; $i++) {
            $num = $siblings[$i];
            $node = $items[$num];
            $childSurvivors = $this->survivors($node['children'], $items);

            $dict = $node['dict']
                ->withEntry(Name::of('Parent'), PdfReference::to($parentNum, 0));
            $dict = $this->setOrDrop($dict, 'Prev', $i > 0 ? $siblings[$i - 1] : null);
            $dict = $this->setOrDrop($dict, 'Next', $i < $count - 1 ? $siblings[$i + 1] : null);
            $dict = $this->setOrDrop($dict, 'First', $childSurvivors !== [] ? $childSurvivors[0] : null);
            $dict = $this->setOrDrop($dict, 'Last', $childSurvivors !== [] ? $childSurvivors[count($childSurvivors) - 1] : null);
            if ($childSurvivors !== []) {
                $dict = $dict->withEntry(Name::of('Count'), PdfNumber::ofInt($this->countDescendants($childSurvivors, $items)));
            } else {
                $dict = $this->dropKey($dict, 'Count');
            }

            $objects[] = IndirectObject::of($num, 0, $dict);
            $this->reemitForest($childSurvivors, $num, $items, $objects);
        }
    }

    /**
     * @param list<int> $children
     * @param array<int, array{dict: Dictionary, children: list<int>, deleted: bool}> $items
     */
    private function countDescendants(array $children, array $items): int
    {
        $total = 0;
        foreach ($children as $num) {
            $total++;
            $total += $this->countDescendants($this->survivors($items[$num]['children'], $items), $items);
        }
        return $total;
    }

    private function setOrDrop(Dictionary $dict, string $key, ?int $objectNumber): Dictionary
    {
        if ($objectNumber === null) {
            return $this->dropKey($dict, $key);
        }
        return $dict->withEntry(Name::of($key), PdfReference::to($objectNumber, 0));
    }

    private function dropKey(Dictionary $dict, string $key): Dictionary
    {
        $rebuilt = Dictionary::empty();
        foreach ($dict->entries() as [$k, $v]) {
            if ($k->value() === $key) {
                continue;
            }
            $rebuilt = $rebuilt->withEntry($k, $v);
        }
        return $rebuilt;
    }
}
