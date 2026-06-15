<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Modify\PageOperations;

use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;

/**
 * Removes /Link annotations that target a deleted page from the surviving pages.
 * A page is re-emitted only when at least one of its links is dropped; an emptied
 * /Annots is removed from the page dict. External URI links and non-link
 * annotations are kept.
 *
 * @internal
 */
final readonly class LinkAnnotationPruner
{
    /**
     * @param list<int> $survivingPageObjects page object numbers kept in the document
     * @param list<int> $deletedPageObjects page object numbers being deleted
     * @return list<IndirectObject>
     */
    public function prune(PdfReader $reader, array $survivingPageObjects, array $deletedPageObjects): array
    {
        if ($deletedPageObjects === []) {
            return [];
        }
        $deleted = array_fill_keys($deletedPageObjects, true);
        $objects = [];

        foreach ($survivingPageObjects as $pageNum) {
            $page = $reader->object($pageNum);
            if (!$page instanceof Dictionary) {
                continue;
            }
            $annotsRaw = $page->get(Name::of('Annots'));
            if ($annotsRaw === null) {
                continue;
            }
            $annots = $reader->resolve($annotsRaw);
            if (!$annots instanceof PdfArray) {
                continue;
            }

            /** @var list<PdfObject> $kept */
            $kept = [];
            $changed = false;
            foreach ($annots->elements() as $el) {
                if ($this->isLinkToDeletedPage($reader, $el, $deleted)) {
                    $changed = true;
                    continue;
                }
                $kept[] = $el;
            }
            if (!$changed) {
                continue;
            }

            if ($kept === []) {
                $objects[] = IndirectObject::of($pageNum, 0, $this->dropKey($page, 'Annots'));
            } else {
                $objects[] = IndirectObject::of($pageNum, 0, $page->withEntry(Name::of('Annots'), PdfArray::of(...$kept)));
            }
        }

        return $objects;
    }

    /** @param array<int, true> $deleted */
    private function isLinkToDeletedPage(PdfReader $reader, PdfObject $el, array $deleted): bool
    {
        $annot = $reader->resolve($el);
        if (!$annot instanceof Dictionary) {
            return false;
        }
        $subtype = $annot->get(Name::of('Subtype'));
        if (!$subtype instanceof Name || $subtype->value() !== 'Link') {
            return false;
        }
        $dest = $annot->get(Name::of('Dest')) ?? $annot->get(Name::of('A'));
        if ($dest === null) {
            return false;
        }
        $target = DestinationTarget::pageObjectNumber($dest, $reader);
        return $target !== null && isset($deleted[$target]);
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
