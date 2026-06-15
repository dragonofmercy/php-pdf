<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Modify\PageOperations;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;

/**
 * Re-emits the /Pages root as a flat tree of the surviving pages (plus any
 * trailing appended-page references). A surviving page is re-emitted only when
 * it must be reparented to the root (multi-level source tree); in that case the
 * inherited /MediaBox, /CropBox, /Rotate and /Resources are materialized onto it
 * when it lacks its own. Already-flat, unmoved pages are left untouched.
 *
 * @internal
 */
final readonly class PageTreeRewriter
{
    /**
     * @param list<PageRecord> $finalPages surviving pages in final order
     * @param list<PdfReference> $appendedPageRefs trailing appended page refs
     * @return list<IndirectObject>
     */
    public function rewrite(PdfReader $reader, PdfReference $pagesRootRef, array $finalPages, array $appendedPageRefs): array
    {
        if ($reader->generationOf($pagesRootRef->objectNumber) !== 0) {
            throw new PdfException('Cannot rewrite /Pages (object ' . $pagesRootRef->objectNumber . '): non-zero generation is not supported');
        }
        $rootDict = $reader->resolve($pagesRootRef);
        if (!$rootDict instanceof Dictionary) {
            throw new PdfException('/Pages does not resolve to a dictionary');
        }

        $objects = [];
        $kids = [];
        foreach ($finalPages as $record) {
            $kids[] = PdfReference::to($record->objectNumber, 0);
            $reparented = $this->reparentIfNeeded($record, $pagesRootRef->objectNumber);
            if ($reparented !== null) {
                if ($reader->generationOf($record->objectNumber) !== 0) {
                    throw new PdfException('Cannot rewrite page (object ' . $record->objectNumber . '): non-zero generation is not supported');
                }
                $objects[] = IndirectObject::of($record->objectNumber, 0, $reparented);
            }
        }
        foreach ($appendedPageRefs as $ref) {
            $kids[] = $ref;
        }

        $objects[] = IndirectObject::of(
            $pagesRootRef->objectNumber,
            0,
            $rootDict
                ->withEntry(Name::of('Kids'), PdfArray::of(...$kids))
                ->withEntry(Name::of('Count'), PdfNumber::ofInt(count($kids))),
        );

        return $objects;
    }

    /**
     * Returns the page dict reparented to the root with materialized inherited
     * attributes, or null when the page is already a direct child of the root.
     */
    private function reparentIfNeeded(PageRecord $record, int $rootObjectNumber): ?Dictionary
    {
        $parent = $record->dict->get(Name::of('Parent'));
        $alreadyRooted = $parent instanceof PdfReference && $parent->objectNumber === $rootObjectNumber;
        if ($alreadyRooted) {
            return null;
        }

        $dict = $record->dict->withEntry(Name::of('Parent'), PdfReference::to($rootObjectNumber, 0));
        $dict = $this->ensureBox($dict, 'MediaBox', $record->mediaBox);
        if ($record->cropBox !== null) {
            $dict = $this->ensureBox($dict, 'CropBox', $record->cropBox);
        }
        if ($record->dict->get(Name::of('Rotate')) === null && $record->rotate !== 0) {
            $dict = $dict->withEntry(Name::of('Rotate'), PdfNumber::ofInt($record->rotate));
        }
        if ($record->dict->get(Name::of('Resources')) === null && $record->resources !== null) {
            $dict = $dict->withEntry(Name::of('Resources'), $record->resources);
        }
        return $dict;
    }

    /** @param list<float> $box */
    private function ensureBox(Dictionary $dict, string $key, array $box): Dictionary
    {
        if ($dict->get(Name::of($key)) !== null) {
            return $dict;
        }
        $elements = array_map(static fn (float $v): PdfNumber => PdfNumber::ofFloat($v), $box);
        return $dict->withEntry(Name::of($key), PdfArray::of(...$elements));
    }
}
