<?php

declare(strict_types=1);

namespace PhpPdf;

use PhpPdf\Exception\PdfException;
use PhpPdf\Writer\Object\PdfNumber;

/**
 * Immutable color value. Factories: rgb (0-255 ints), hex (#rrggbb, rrggbb,
 * #rgb, rgb), gray (0-255 int).
 */
final readonly class Color
{
    private function __construct(
        private float $r,
        private float $g,
        private float $b,
        private bool $isGray,
    ) {}

    public static function rgb(int $r, int $g, int $b): self
    {
        foreach ([$r, $g, $b] as $c) {
            if ($c < 0 || $c > 255) {
                throw new PdfException("RGB component out of range (0-255): {$c}");
            }
        }
        return new self($r / 255, $g / 255, $b / 255, isGray: false);
    }

    public static function hex(string $hex): self
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            throw new PdfException("Invalid hex color: {$hex}");
        }
        $r = (int) hexdec(substr($hex, 0, 2));
        $g = (int) hexdec(substr($hex, 2, 2));
        $b = (int) hexdec(substr($hex, 4, 2));
        return self::rgb($r, $g, $b);
    }

    public static function gray(int $level): self
    {
        if ($level < 0 || $level > 255) {
            throw new PdfException("Gray level out of range (0-255): {$level}");
        }
        $f = $level / 255;
        return new self($f, $f, $f, isGray: true);
    }

    /**
     * Emits a PDF color-setting operator:
     *   - stroke=false → "r g b rg" or "l g" (fill)
     *   - stroke=true  → "r g b RG" or "l G" (stroke)
     *
     * @internal
     */
    public function toPdfOperator(bool $stroke): string
    {
        if ($this->isGray) {
            $op = $stroke ? 'G' : 'g';
            return PdfNumber::ofFloat($this->r)->toBytes() . ' ' . $op;
        }
        $op = $stroke ? 'RG' : 'rg';
        return PdfNumber::ofFloat($this->r)->toBytes()
            . ' ' . PdfNumber::ofFloat($this->g)->toBytes()
            . ' ' . PdfNumber::ofFloat($this->b)->toBytes()
            . ' ' . $op;
    }
}
