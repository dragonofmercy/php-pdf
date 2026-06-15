<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill\Font;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Font\WinAnsiEncoder;
use DragonOfMercy\PhpPdf\Form\Fill\PdfLiteralEscape;

/**
 * AppearanceFont for the Standard 14 fonts: WinAnsi single-byte encoding and
 * metric widths from MetricsRegistry. Byte-identical to the pre-seam behavior.
 */
final class Standard14AppearanceFont implements AppearanceFont
{
    public function __construct(
        private readonly Font $font,
        private readonly MetricsRegistry $metrics,
    ) {}

    public function measureWidth(string $text, float $size): float
    {
        return $this->metrics->metricsFor($this->font)->stringWidth(WinAnsiEncoder::encode($text), $size);
    }

    public function encodeShowOperand(string $text): string
    {
        return '(' . PdfLiteralEscape::escape(WinAnsiEncoder::encode($text)) . ')';
    }
}
