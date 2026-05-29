<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

/**
 * Shared intrinsic-width accessor for 1D barcodes carrying an optional module
 * size. The using class must have a `public readonly ?float $moduleSize`
 * property initialised in its constructor, and must implement
 * {@see SizedBarcode::withModuleSize()} (each barcode rebuilds `new self(...)`
 * with its own field list) and {@see SizedBarcode::widthForModule()} (each
 * format has its own module-count formula). Mirrors {@see Orientable}.
 */
trait Sized
{
    /**
     * The total width for the configured module size, or null when no module
     * size has been set (the caller must then supply an explicit width).
     */
    public function intrinsicWidth(): ?float
    {
        return $this->moduleSize === null ? null : $this->widthForModule($this->moduleSize);
    }

    abstract public function withModuleSize(float $moduleSize): self;

    abstract public function widthForModule(float $moduleSize): float;
}
