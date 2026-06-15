<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Modify\PageOperations;

use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;

/**
 * Prunes named destinations (the /Names /Dests name tree and the legacy catalog
 * /Dests dictionary) whose destination resolves to a deleted page. Re-emits each
 * changed name-tree node / dictionary; emptied leaves keep an empty /Names array.
 *
 * @internal
 */
final readonly class NamedDestinationPruner
{
    /**
     * @param list<int> $deletedPageObjects
     * @return list<IndirectObject>
     */
    public function prune(PdfReader $reader, array $deletedPageObjects): array
    {
        if ($deletedPageObjects === []) {
            return [];
        }
        $deleted = array_fill_keys($deletedPageObjects, true);
        $objects = [];

        $catalog = $reader->catalog();

        $names = $reader->resolve($catalog->get(Name::of('Names')) ?? PdfNull::instance());
        if ($names instanceof Dictionary) {
            $destsEntry = $names->get(Name::of('Dests'));
            if ($destsEntry instanceof PdfReference) {
                $this->pruneNameTreeNode($reader, $destsEntry, $deleted, $objects);
            }
        }

        $legacyRef = $catalog->get(Name::of('Dests'));
        if ($legacyRef instanceof PdfReference) {
            $legacy = $reader->resolve($legacyRef);
            if ($legacy instanceof Dictionary) {
                $kept = Dictionary::empty();
                $changed = false;
                foreach ($legacy->entries() as [$key, $value]) {
                    if ($this->targetsDeleted($reader, $value, $deleted)) {
                        $changed = true;
                        continue;
                    }
                    $kept = $kept->withEntry($key, $value);
                }
                if ($changed) {
                    $objects[] = IndirectObject::of($legacyRef->objectNumber, 0, $kept);
                }
            }
        }

        return $objects;
    }

    /**
     * @param array<int, true> $deleted
     * @param list<IndirectObject> $objects
     */
    private function pruneNameTreeNode(PdfReader $reader, PdfReference $nodeRef, array $deleted, array &$objects, int $depth = 0): void
    {
        if ($depth > 64) {
            return;
        }
        $node = $reader->resolve($nodeRef);
        if (!$node instanceof Dictionary) {
            return;
        }

        $kids = $reader->resolve($node->get(Name::of('Kids')) ?? PdfNull::instance());
        if ($kids instanceof PdfArray) {
            foreach ($kids->elements() as $kid) {
                if ($kid instanceof PdfReference) {
                    $this->pruneNameTreeNode($reader, $kid, $deleted, $objects, $depth + 1);
                }
            }
            return;
        }

        $namesArr = $reader->resolve($node->get(Name::of('Names')) ?? PdfNull::instance());
        if (!$namesArr instanceof PdfArray) {
            return;
        }
        $els = $namesArr->elements();
        /** @var list<PdfObject> $kept */
        $kept = [];
        $changed = false;
        for ($i = 0; $i + 1 < count($els); $i += 2) {
            $key = $els[$i];
            $dest = $els[$i + 1];
            if ($this->targetsDeleted($reader, $dest, $deleted)) {
                $changed = true;
                continue;
            }
            $kept[] = $key;
            $kept[] = $dest;
        }
        if ($changed) {
            $rebuilt = Dictionary::empty();
            foreach ($node->entries() as [$k, $v]) {
                if ($k->value() === 'Names' || $k->value() === 'Limits') {
                    continue;
                }
                $rebuilt = $rebuilt->withEntry($k, $v);
            }
            $rebuilt = $rebuilt->withEntry(Name::of('Names'), PdfArray::of(...$kept));
            $objects[] = IndirectObject::of($nodeRef->objectNumber, 0, $rebuilt);
        }
    }

    /** @param array<int, true> $deleted */
    private function targetsDeleted(PdfReader $reader, PdfObject $dest, array $deleted): bool
    {
        $target = DestinationTarget::pageObjectNumber($dest, $reader);
        return $target !== null && isset($deleted[$target]);
    }
}
