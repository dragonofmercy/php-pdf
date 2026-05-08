<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Per-side cell padding (top/right/bottom/left). Values share whatever
 * unit they were constructed in -- the document unit when handed to
 * Page::cell() / Page::setCellsPadding(), or points internally.
 *
 * Use the named constructors below for clarity over positional args.
 */
final readonly class CellPadding
{
    public function __construct(
        public float $top,
        public float $right,
        public float $bottom,
        public float $left,
    ) {
        foreach (['top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left] as $side => $value) {
            if ($value < 0) {
                throw new PdfException("Cell padding {$side} cannot be negative, got {$value}");
            }
        }
    }

    /** Same value on all four sides. */
    public static function all(float $value): self
    {
        return new self($value, $value, $value, $value);
    }

    /** vertical = top + bottom, horizontal = left + right. */
    public static function symmetric(float $vertical, float $horizontal): self
    {
        return new self($vertical, $horizontal, $vertical, $horizontal);
    }

    /** Per-side, omitted sides default to 0. */
    public static function sides(float $top = 0, float $right = 0, float $bottom = 0, float $left = 0): self
    {
        return new self($top, $right, $bottom, $left);
    }
}
