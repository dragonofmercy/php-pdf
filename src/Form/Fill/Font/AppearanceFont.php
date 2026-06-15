<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill\Font;

/**
 * Measures and encodes show-text for one AcroForm appearance font, abstracting
 * Standard-14 (WinAnsi single-byte) from embedded fonts. The appearance stream's
 * /Resources /Font still references the /DR font by name; this only governs the
 * width math and the bytes inside the show-text operator.
 */
interface AppearanceFont
{
    /** Width of $text at $size, in points. */
    public function measureWidth(string $text, float $size): float;

    /**
     * The show-text operand including its delimiters: a "(...)" literal for
     * single-byte fonts or a "<...>" hex string for composite fonts. Callers
     * append " Tj".
     */
    public function encodeShowOperand(string $text): string;
}
