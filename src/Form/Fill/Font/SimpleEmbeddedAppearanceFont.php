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
final readonly class SimpleEmbeddedAppearanceFont implements AppearanceFont
{
    public function __construct(
        private SimpleFontProgram $program,
        private string $fieldName,
    ) {}

    public function measureWidth(string $text, float $size): float
    {
        $totalEm = 0;
        foreach (Utf8::codepoints($text) as [$cp, $_]) {
            $code = $this->codepointToCode($cp);
            $totalEm += $this->program->codeWidths[$code] ?? $this->program->missingWidth;
        }
        return $totalEm / 1000.0 * $size;
    }

    public function encodeShowOperand(string $text): string
    {
        $bytes = '';
        foreach (Utf8::codepoints($text) as [$cp, $_]) {
            $bytes .= chr($this->codepointToCode($cp) & 0xFF);
        }
        return '(' . PdfLiteralEscape::escape($bytes) . ')';
    }

    /**
     * Returns the byte code for a unicode codepoint, or throws PdfException when
     * the codepoint is not in the font encoding (or is an invalid UTF-8 sequence).
     */
    private function codepointToCode(int $cp): int
    {
        if (!isset($this->program->unicodeToCode[$cp])) {
            // Utf8::codepoints yields -1 for an invalid byte sequence; format that
            // case explicitly rather than letting sprintf emit a 64-bit garbage value.
            if ($cp < 0) {
                throw new PdfException(sprintf(
                    'Field "%s": text contains an invalid UTF-8 byte sequence',
                    $this->fieldName,
                ));
            }
            throw new PdfException(sprintf(
                'Field "%s": character "%s" (U+%04X) is not in the font encoding',
                $this->fieldName,
                mb_chr($cp, 'UTF-8'),
                $cp,
            ));
        }
        return $this->program->unicodeToCode[$cp];
    }
}
