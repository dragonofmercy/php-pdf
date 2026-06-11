<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Optional visible appearance for a signature placed on an existing PDF via
 * Pdf::sign(). The widget is drawn on the existing page $pageIndex (0-based);
 * x/y/width/height are in the document unit (Pdf opens in points) with y
 * top-down (flipped against the page MediaBox at emit time). $caption, when
 * set, is rendered as Helvetica text lines inside the box.
 */
final readonly class SignatureAppearance
{
    public function __construct(
        public int $pageIndex,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public ?string $caption = null,
    ) {
        if ($pageIndex < 0) {
            throw new PdfException("Signature appearance page index cannot be negative, got {$pageIndex}");
        }
        if ($width <= 0) {
            throw new PdfException('Signature appearance width must be positive, got ' . self::fmt($width));
        }
        if ($height <= 0) {
            throw new PdfException('Signature appearance height must be positive, got ' . self::fmt($height));
        }
    }

    private static function fmt(float $v): string
    {
        return (float) (int) $v === $v ? (string) (int) $v : (string) $v;
    }
}
