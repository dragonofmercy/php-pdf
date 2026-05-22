<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

/**
 * Shared orientation accessors for 1D barcodes. The using class must have a
 * `public readonly Orientation $orientation` property initialised in its
 * constructor, and must implement {@see OrientableBarcode::withOrientation()}
 * (each barcode rebuilds `new self(...)` with its own field list).
 */
trait Orientable
{
    /** The current render orientation (Horizontal by default). */
    public function orientation(): Orientation
    {
        return $this->orientation;
    }

    /** Returns a copy rendered vertically (90 degrees counter-clockwise, bottom-left). */
    public function vertical(): self
    {
        return $this->withOrientation(Orientation::Vertical);
    }

    /** Returns a copy rendered horizontally (the default). */
    public function horizontal(): self
    {
        return $this->withOrientation(Orientation::Horizontal);
    }

    abstract public function withOrientation(Orientation $orientation): self;
}
