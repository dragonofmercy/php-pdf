<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Outline;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;

/**
 * Internal PDF destination (page + position). Used both by outline nodes and
 * by internal hyperlinks (`Link::destination()`). Pages are 0-indexed
 * throughout the Outline namespace - the same indexing as the underlying
 * `$this->pages` array on `Document`. Coordinates are in the document's user
 * unit and use the top-down Y axis (consistent with `Page::text()`,
 * `Page::cell()`, and `OpenAction`); the Y-flip to PDF native bottom-up is
 * applied at emission time (see {@see OutlineEmitter}, {@see LinkAnnotationEmitter}).
 *
 * Use the named constructors:
 * - {@see page()}     XYZ top-left, no zoom (the safe default).
 * - {@see xyz()}      arbitrary left/top/zoom (null = "keep current" per PDF spec).
 * - {@see fit()}      whole page fits the viewport.
 * - {@see fitWidth()} page width fills the viewport, scrolled to a given top.
 */
final readonly class Destination
{
    private function __construct(
        public int $pageIndex,
        public DestinationFit $fit,
        public ?float $left = null,
        public ?float $top = null,
        public ?float $zoom = null,
    ) {
        if ($pageIndex < 0) {
            throw new PdfException('Destination pageIndex must be non-negative, got ' . $pageIndex);
        }
    }

    /** XYZ destination anchored at the page top-left with the viewer's current zoom. */
    public static function page(int $pageIndex): self
    {
        return new self($pageIndex, DestinationFit::Xyz, left: 0.0, top: 0.0, zoom: null);
    }

    /**
     * Arbitrary XYZ destination. Any of `$left`, `$top`, `$zoom` may be
     * `null` to mean "keep the viewer's current value" per PDF 1.7
     * section 12.3.2.2.
     */
    public static function xyz(int $pageIndex, ?float $left, ?float $top, ?float $zoom = null): self
    {
        return new self($pageIndex, DestinationFit::Xyz, left: $left, top: $top, zoom: $zoom);
    }

    /** Whole page fits in the viewport. */
    public static function fit(int $pageIndex): self
    {
        return new self($pageIndex, DestinationFit::Fit);
    }

    /**
     * Page width fills the viewport, scrolled so that `$top` (top-down,
     * document unit) appears at the top of the viewport. `null` means
     * "keep current".
     */
    public static function fitWidth(int $pageIndex, ?float $top = null): self
    {
        return new self($pageIndex, DestinationFit::FitH, top: $top);
    }

    /**
     * Serialises this destination to its PDF on-disk representation
     * `[pageRef /Variant args...]`. Shared between `OutlineEmitter::emit()`
     * (outline `/Dest` entry) and `LinkAnnotationEmitter::emit()` (GoTo
     * action `/D` entry). Resolves `$pageIndex` against `$pageRefs` /
     * `$pageHeightsPt` (matched 1:1) and applies the Y-flip from top-down
     * user coords to bottom-up PDF native coords using the target page's
     * height.
     *
     * @param list<PdfReference> $pageRefs
     * @param list<float>        $pageHeightsPt
     *
     * @throws PdfException when `$pageIndex` is outside `[0, count($pageRefs))`
     */
    public function toPdfArray(array $pageRefs, array $pageHeightsPt, Unit $unit, string $context): PdfArray
    {
        $pageCount = count($pageRefs);
        if ($this->pageIndex < 0 || $this->pageIndex >= $pageCount) {
            throw new PdfException(sprintf(
                'Destination references out-of-bounds page index %d (document has %d page(s)) for %s',
                $this->pageIndex,
                $pageCount,
                $context,
            ));
        }
        $pageRef = $pageRefs[$this->pageIndex];
        $targetHeightPt = $pageHeightsPt[$this->pageIndex];

        return match ($this->fit) {
            DestinationFit::Fit => PdfArray::of($pageRef, Name::of('Fit')),
            DestinationFit::FitH => PdfArray::of(
                $pageRef,
                Name::of('FitH'),
                PdfNumber::ofFloat(
                    $this->top === null ? $targetHeightPt : $targetHeightPt - $unit->toPoints($this->top),
                ),
            ),
            DestinationFit::Xyz => PdfArray::of(
                $pageRef,
                Name::of('XYZ'),
                PdfNumber::ofFloat($this->left === null ? 0.0 : $unit->toPoints($this->left)),
                PdfNumber::ofFloat(
                    $this->top === null ? $targetHeightPt : $targetHeightPt - $unit->toPoints($this->top),
                ),
                PdfNumber::ofFloat($this->zoom ?? 0.0),
            ),
        };
    }
}
