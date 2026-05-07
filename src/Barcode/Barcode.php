<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

use DragonOfMercy\PhpPdf\{Color, Page};

/**
 * A drawable barcode -- either 1D (EAN-13, EAN-8, Code 128) or 2D (QR Code).
 * Implementations are immutable value objects constructed via named factories
 * on the concrete class. Use {@see \DragonOfMercy\PhpPdf\Page::barcode()}
 * to draw onto a page.
 */
interface Barcode
{
    /**
     * Returns a copy of the barcode with the given foreground color.
     * Default color (when withColor is never called) is black.
     */
    public function withColor(Color $color): self;

    /**
     * Renders the barcode onto the page at (x, y) with width w and height h.
     * Coordinates are in the page's unit, top-down Y axis.
     * (x, y) is the top-left corner of the barcode bounding box (including the quiet zone), in the page's unit.
     *
     * For 1D barcodes, h is required; passing null throws.
     * For QR (which is square), h is optional: if null, h = w; if provided,
     * it must equal w or a PdfException is thrown.
     *
     * This method is not part of the public drawing API; call
     * {@see \DragonOfMercy\PhpPdf\Page::barcode()} instead. Implementations
     * must wrap their rendering in `q ... Q` so the page's graphics state
     * is unchanged after the call.
     */
    public function draw(Page $page, float $x, float $y, float $w, ?float $h): void;
}
