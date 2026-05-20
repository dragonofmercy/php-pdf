<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Outline;

use DragonOfMercy\PhpPdf\Exception\PdfException;

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
}
