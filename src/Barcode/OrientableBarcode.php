<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

/**
 * A 1D barcode that can be rendered horizontally or vertically.
 * 2D barcodes implement {@see Barcode} only and are not orientable.
 */
interface OrientableBarcode extends Barcode
{
    /** Returns a copy with the given orientation. Default (factory) is Horizontal. */
    public function withOrientation(Orientation $orientation): self;

    /** Returns the current orientation; Horizontal unless changed via withOrientation(). */
    public function orientation(): Orientation;
}
