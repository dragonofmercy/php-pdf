<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Outline;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;

/**
 * Serialises an outline tree (root `OutlineNode` + descendants) into the
 * sequence of `IndirectObject`s that PDF 1.7 section 12.3.3 prescribes:
 *
 * - One `/Type /Outlines` object for the root, with `/First`, `/Last`,
 *   `/Count` pointing at its children.
 * - One object per non-root node with `/Title (...)`, `/Parent`, optionally
 *   `/First` `/Last` `/Count` (if the node has children), and `/Prev`
 *   `/Next` (sibling chain). All carry `/Dest [pageRef /Variant ...]`
 *   resolved against the document's `$pageRefs`/`$pageHeightsPt`.
 *
 * Always emits "open" outlines (positive `/Count` = total open descendants).
 * The two passes are: (1) allocate one object id per node, (2) build each
 * object now that all ids are known.
 *
 * @internal
 */
final readonly class OutlineEmitter
{
    public function __construct(private Unit $unit) {}

    /**
     * @param list<PdfReference> $pageRefs
     * @param list<float>        $pageHeightsPt
     * @param-out int            $nextId  advanced past the last allocated id
     *
     * @return array{outlinesRef: PdfReference, objects: list<IndirectObject>}
     */
    public function emit(
        OutlineNode $root,
        array $pageRefs,
        array $pageHeightsPt,
        int &$nextId,
        string $context,
    ): array {
        /** @var array<int, int> $idMap spl_object_id(node) -> PDF object id */
        $idMap = [];
        /** @var array<int, int> $countMap spl_object_id(node) -> open descendant count */
        $countMap = [];
        $this->allocateIds($root, $idMap, $nextId);
        $this->computeCounts($root, $countMap);

        /** @var list<IndirectObject> $objects */
        $objects = [];
        $this->emitNode($root, $idMap, $countMap, $pageRefs, $pageHeightsPt, $context, $objects);

        $outlinesRef = PdfReference::to($idMap[spl_object_id($root)], 0);
        return ['outlinesRef' => $outlinesRef, 'objects' => $objects];
    }

    /**
     * @param array<int, int> $idMap
     */
    private function allocateIds(OutlineNode $node, array &$idMap, int &$nextId): void
    {
        $idMap[spl_object_id($node)] = $nextId++;
        foreach ($node->children() as $child) {
            $this->allocateIds($child, $idMap, $nextId);
        }
    }

    /**
     * @param array<int, int> $countMap
     */
    private function computeCounts(OutlineNode $node, array &$countMap): int
    {
        $sum = 0;
        foreach ($node->children() as $child) {
            $sum += 1 + $this->computeCounts($child, $countMap);
        }
        $countMap[spl_object_id($node)] = $sum;
        return $sum;
    }

    /**
     * @param array<int, int>      $idMap
     * @param array<int, int>      $countMap
     * @param list<PdfReference>   $pageRefs
     * @param list<float>          $pageHeightsPt
     * @param list<IndirectObject> $objects (by-ref accumulator)
     */
    private function emitNode(
        OutlineNode $node,
        array $idMap,
        array $countMap,
        array $pageRefs,
        array $pageHeightsPt,
        string $context,
        array &$objects,
    ): void {
        $nodeId = $idMap[spl_object_id($node)];
        $children = $node->children();

        $dict = Dictionary::empty();

        if ($node->isRoot()) {
            $dict = $dict->withEntry(Name::of('Type'), Name::of('Outlines'));
        } else {
            $title = $node->title();
            if ($title === null) {
                throw new PdfException("Non-root outline node has null title for {$context}");
            }
            $destination = $node->destination();
            if ($destination === null) {
                throw new PdfException("Non-root outline node has null destination for {$context}");
            }
            $parent = $node->parent();
            if ($parent === null) {
                throw new PdfException("Non-root outline node has null parent for {$context}");
            }
            $dict = $dict
                ->withEntry(Name::of('Title'), PdfString::of($title))
                ->withEntry(Name::of('Parent'), PdfReference::to($idMap[spl_object_id($parent)], 0));

            $siblings = $parent->children();
            $position = array_search($node, $siblings, true);
            if ($position === false) {
                throw new PdfException("Outline node not found in parent's children list for {$context}");
            }
            if ($position > 0) {
                $dict = $dict->withEntry(
                    Name::of('Prev'),
                    PdfReference::to($idMap[spl_object_id($siblings[$position - 1])], 0),
                );
            }
            if ($position < count($siblings) - 1) {
                $dict = $dict->withEntry(
                    Name::of('Next'),
                    PdfReference::to($idMap[spl_object_id($siblings[$position + 1])], 0),
                );
            }

            $dict = $dict->withEntry(
                Name::of('Dest'),
                $this->buildDestinationArray($destination, $pageRefs, $pageHeightsPt, $context),
            );
        }

        if ($children !== []) {
            $first = $children[0];
            $last = $children[count($children) - 1];
            $dict = $dict
                ->withEntry(Name::of('First'), PdfReference::to($idMap[spl_object_id($first)], 0))
                ->withEntry(Name::of('Last'), PdfReference::to($idMap[spl_object_id($last)], 0))
                ->withEntry(Name::of('Count'), PdfNumber::ofInt($countMap[spl_object_id($node)]));
        }

        $objects[] = IndirectObject::of($nodeId, 0, $dict);

        foreach ($children as $child) {
            $this->emitNode($child, $idMap, $countMap, $pageRefs, $pageHeightsPt, $context, $objects);
        }
    }

    /**
     * Mirror of `LinkAnnotationEmitter::buildDestinationArray()`. Kept here as
     * a private duplicate intentionally - extraction can happen later if the
     * two diverge in scope. Tests in both emitters lock the expected output.
     *
     * @param list<PdfReference> $pageRefs
     * @param list<float>        $pageHeightsPt
     */
    private function buildDestinationArray(
        Destination $dest,
        array $pageRefs,
        array $pageHeightsPt,
        string $context,
    ): PdfArray {
        $idx = $dest->pageIndex;
        $pageCount = count($pageRefs);
        if ($idx < 0 || $idx >= $pageCount) {
            throw new PdfException(sprintf(
                'Destination references out-of-bounds page index %d (document has %d page(s)) for %s',
                $idx,
                $pageCount,
                $context,
            ));
        }
        $pageRef = $pageRefs[$idx];
        $targetHeightPt = $pageHeightsPt[$idx];

        return match ($dest->fit) {
            DestinationFit::Fit => PdfArray::of($pageRef, Name::of('Fit')),
            DestinationFit::FitH => PdfArray::of(
                $pageRef,
                Name::of('FitH'),
                PdfNumber::ofFloat(
                    $dest->top === null ? $targetHeightPt : $targetHeightPt - $this->unit->toPoints($dest->top),
                ),
            ),
            DestinationFit::Xyz => PdfArray::of(
                $pageRef,
                Name::of('XYZ'),
                PdfNumber::ofFloat($dest->left === null ? 0.0 : $this->unit->toPoints($dest->left)),
                PdfNumber::ofFloat(
                    $dest->top === null ? $targetHeightPt : $targetHeightPt - $this->unit->toPoints($dest->top),
                ),
                PdfNumber::ofFloat($dest->zoom ?? 0.0),
            ),
        };
    }
}
