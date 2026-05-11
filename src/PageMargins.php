<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Per-side page margins in the document's unit. Used as the reserved zone
 * for header (top) and footer (bottom) callbacks, and as the default
 * positioning anchor for cell() row starts.
 */
final readonly class PageMargins
{
    public function __construct(
        public float $top,
        public float $right,
        public float $bottom,
        public float $left,
    ) {
        if ($top < 0 || $right < 0 || $bottom < 0 || $left < 0) {
            throw new PdfException('PageMargins values must be non-negative');
        }
    }

    public static function all(float $value): self
    {
        return new self($value, $value, $value, $value);
    }
}
