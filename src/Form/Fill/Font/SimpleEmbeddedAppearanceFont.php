<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill\Font;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\Utf8;
use DragonOfMercy\PhpPdf\Form\Fill\PdfLiteralEscape;

/**
 * AppearanceFont for embedded simple fonts (Type1 / TrueType / MMType1).
 * Uses the pre-parsed SimpleFontProgram for width measurement and single-byte encoding.
 *
 * @internal
 */
final class SimpleEmbeddedAppearanceFont implements AppearanceFont
{
    public function __construct(
        private readonly SimpleFontProgram $program,
        private readonly string $fieldName,
    ) {}

    public function measureWidth(string $text, float $size): float
    {
        $totalEm = 0;
        foreach (Utf8::codepoints($text) as [$cp, $_]) {
            if (!isset($this->program->unicodeToCode[$cp])) {
                $this->throwMissingChar($cp);
            }
            $code = $this->program->unicodeToCode[$cp];
            $totalEm += $this->program->codeWidths[$code] ?? $this->program->missingWidth;
        }
        return $totalEm / 1000.0 * $size;
    }

    public function encodeShowOperand(string $text): string
    {
        $bytes = '';
        foreach (Utf8::codepoints($text) as [$cp, $_]) {
            if (!isset($this->program->unicodeToCode[$cp])) {
                $this->throwMissingChar($cp);
            }
            $bytes .= chr($this->program->unicodeToCode[$cp] & 0xFF);
        }
        return '(' . PdfLiteralEscape::escape($bytes) . ')';
    }

    /** @return never */
    private function throwMissingChar(int $cp): never
    {
        $char = $cp >= 0 ? mb_chr($cp, 'UTF-8') : sprintf('U+%04X', $cp);
        throw new PdfException(sprintf(
            'Field "%s": character "%s" (U+%04X) is not in the font encoding',
            $this->fieldName,
            $char,
            $cp,
        ));
    }
}
