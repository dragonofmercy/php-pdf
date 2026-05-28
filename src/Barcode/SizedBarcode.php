<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

/**
 * Optional companion to {@see Barcode} for codes that carry an intrinsic
 * module size and can therefore be drawn without an explicit w. Implemented
 * by the 1D barcodes (Code 39, Code 93, Code 128, EAN-8, EAN-13, ITF, UPC-A);
 * 2D barcodes do not implement this.
 *
 * Typical fluent usage:
 *
 * ```
 * $page->barcode(
 *     Itf::ofGtin14('1234567890123')->withModuleSize(0.33),
 *     x: 10, y: 10, h: 20,
 * );
 * ```
 *
 * Module size is expressed in the document's unit (millimetres by default,
 * points when the Document was created with Unit::PT), same convention as x,
 * y, w, h on Page::barcode().
 *
 * Pre-computing the width via {@see Barcode}::widthForModule($size) and then
 * passing it as $w to Page::barcode() remains a valid alternative.
 */
interface SizedBarcode extends Barcode
{
    /**
     * Returns the total width (quiet zones included) the barcode would render
     * to for the given module size, expressed in the document's unit. Does not
     * store the value; pass it to {@see Page::barcode()} as $w, or call
     * {@see self::withModuleSize()} to make it intrinsic.
     */
    public function widthForModule(float $moduleSize): float;

    /**
     * Returns a copy of the barcode with the given module size stored.
     * When set, Page::barcode() may be called without $w and will compute
     * it from {@see self::intrinsicWidth()}.
     * Throws when $moduleSize is not strictly positive.
     */
    public function withModuleSize(float $moduleSize): self;

    /**
     * Returns the total width (quiet zones included) that the barcode would
     * render to given its stored module size, in the document unit, or null
     * when no module size has been set (withModuleSize() never called).
     */
    public function intrinsicWidth(): ?float;
}
