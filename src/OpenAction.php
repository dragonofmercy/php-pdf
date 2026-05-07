<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;

/**
 * Initial view applied when the document is first opened (PDF 1.7 §12.6.4.2,
 * /OpenAction with a Go-To destination). Pages are 1-indexed; coordinates are
 * expressed in the document's user unit with a top-down Y axis (consistent
 * with the rest of the public API), and converted to PDF native (bottom-up,
 * in points) at serialisation.
 *
 * Use the named constructors rather than calling the private constructor:
 * - {@see fit()}        full page in viewport.
 * - {@see fitWidth()}   page width fills viewport, scrolled to a given top.
 * - {@see fitHeight()}  page height fills viewport, scrolled to a given left.
 * - {@see zoom()}       arbitrary corner + zoom factor.
 * - {@see actualSize()} 100% zoom anchored at the page's top-left.
 */
final readonly class OpenAction
{
    private function __construct(
        public int $pageIndex,
        public string $fitMode,
        public ?float $top = null,
        public ?float $left = null,
        public ?float $zoom = null,
    ) {}

    public static function fit(int $page = 1): self
    {
        return new self($page, 'Fit');
    }

    /**
     * Fit width: page width fills the viewport, scrolled so that the given
     * vertical position appears at the top of the viewport. `null` (default)
     * anchors at the very top of the page.
     */
    public static function fitWidth(int $page = 1, ?float $top = null): self
    {
        return new self($page, 'FitH', top: $top);
    }

    /**
     * Fit height: page height fills the viewport, scrolled so that the given
     * horizontal position appears at the left of the viewport. `null` (default)
     * anchors at the very left of the page.
     */
    public static function fitHeight(int $page = 1, ?float $left = null): self
    {
        return new self($page, 'FitV', left: $left);
    }

    /**
     * Arbitrary destination: top-left corner at (`$left`, `$top`) of the page,
     * displayed at the given zoom (1.0 = 100%, 2.0 = 200%, ...). Passing 0
     * for `$zoom` keeps the viewer's current zoom level.
     */
    public static function zoom(int $page = 1, float $left = 0.0, float $top = 0.0, float $zoom = 1.0): self
    {
        return new self($page, 'XYZ', top: $top, left: $left, zoom: $zoom);
    }

    /**
     * Actual size (100% zoom) anchored at the page's top-left corner.
     */
    public static function actualSize(int $page = 1): self
    {
        return new self($page, 'XYZ', top: 0.0, left: 0.0, zoom: 1.0);
    }

    /**
     * Builds the destination array `[pageRef /Mode args...]` for the PDF
     * catalog. The page's height is required to flip top-down user coordinates
     * to PDF's bottom-up coordinate system.
     *
     * @internal
     */
    public function toPdfArray(PdfReference $pageRef, float $pageHeightPt, Unit $unit): PdfArray
    {
        $top = $this->top === null
            ? $pageHeightPt
            : $pageHeightPt - $unit->toPoints($this->top);
        $left = $this->left === null ? 0.0 : $unit->toPoints($this->left);

        return match ($this->fitMode) {
            'Fit'  => PdfArray::of($pageRef, Name::of('Fit')),
            'FitH' => PdfArray::of($pageRef, Name::of('FitH'), PdfNumber::ofFloat($top)),
            'FitV' => PdfArray::of($pageRef, Name::of('FitV'), PdfNumber::ofFloat($left)),
            'XYZ'  => PdfArray::of(
                $pageRef,
                Name::of('XYZ'),
                PdfNumber::ofFloat($left),
                PdfNumber::ofFloat($top),
                PdfNumber::ofFloat($this->zoom ?? 0.0),
            ),
            default => throw new \LogicException("Unknown fit mode: {$this->fitMode}"),
        };
    }
}
