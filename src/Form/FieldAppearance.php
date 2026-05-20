<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\TextAlign;

/**
 * Visual appearance attributes shared by every AcroForm field VO. All
 * properties are optional; null values mean "use the PDF default" (noir,
 * Helvetica 10pt, transparent background, no border color override).
 */
final readonly class FieldAppearance
{
    public function __construct(
        public ?Color $borderColor = null,
        public ?float $borderWidth = null,
        public ?Color $backgroundColor = null,
        public ?Color $textColor = null,
        public ?Font $font = null,
        public ?float $fontSize = null,
        public TextAlign $align = TextAlign::LEFT,
    ) {
        if ($borderWidth !== null && $borderWidth < 0) {
            throw new PdfException(sprintf(
                'Field appearance borderWidth cannot be negative, got %s',
                self::formatNumber($borderWidth),
            ));
        }
        if ($fontSize !== null && $fontSize <= 0) {
            throw new PdfException(sprintf(
                'Field appearance fontSize must be positive, got %s',
                self::formatNumber($fontSize),
            ));
        }
    }

    private static function formatNumber(float $v): string
    {
        if ((float) (int) $v === $v) {
            return (string) (int) $v;
        }
        return (string) $v;
    }
}
