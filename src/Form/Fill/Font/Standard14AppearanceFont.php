<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill\Font;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\Custom\Utf8;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Font\WinAnsiEncoder;
use DragonOfMercy\PhpPdf\Form\Fill\PdfLiteralEscape;

/**
 * AppearanceFont for the Standard 14 fonts: WinAnsi single-byte encoding and
 * metric widths from MetricsRegistry. Byte-identical to the pre-seam behavior.
 *
 * When the /DR font dictionary carries an /Encoding dictionary with /Differences
 * (Acrobat's /Helv uses PDFDocEncoding this way), the show operand is encoded through
 * that map instead, so typographic quotes, dashes, the euro sign or bullets render as themselves rather than
 * as the glyph WinAnsi would put at the same byte. Widths still come from the WinAnsi
 * metrics: the glyph is the same, only its code differs.
 */
final class Standard14AppearanceFont implements AppearanceFont
{
    /**
     * @param array<int, int>|null $unicodeToCode unicode -> byte code map of the /DR font's
     *                                            /Encoding dictionary, null for plain WinAnsi
     */
    public function __construct(
        private readonly Font $font,
        private readonly MetricsRegistry $metrics,
        private readonly ?array $unicodeToCode = null,
    ) {}

    public function measureWidth(string $text, float $size): float
    {
        return $this->metrics->metricsFor($this->font)->stringWidth(WinAnsiEncoder::encode($text), $size);
    }

    public function encodeShowOperand(string $text): string
    {
        if ($this->unicodeToCode === null) {
            return '(' . PdfLiteralEscape::escape(WinAnsiEncoder::encode($text)) . ')';
        }

        $bytes = '';
        foreach (Utf8::codepoints($text) as [$cp, $_]) {
            // Unmapped codepoints become '?' (0x3F), like WinAnsiEncoder does.
            $bytes .= chr(($this->unicodeToCode[$cp] ?? 0x3F) & 0xFF);
        }

        return '(' . PdfLiteralEscape::escape($bytes) . ')';
    }
}
