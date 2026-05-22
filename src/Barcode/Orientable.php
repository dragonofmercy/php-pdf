<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

/**
 * Shared orientation accessors for 1D barcodes. The using class MUST declare a
 * promoted `public Orientation $orientation` property and implement
 * {@see OrientableBarcode::withOrientation()}.
 */
trait Orientable
{
    public function orientation(): Orientation
    {
        return $this->orientation;
    }

    public function vertical(): self
    {
        return $this->withOrientation(Orientation::Vertical);
    }

    public function horizontal(): self
    {
        return $this->withOrientation(Orientation::Horizontal);
    }

    abstract public function withOrientation(Orientation $orientation): self;
}
