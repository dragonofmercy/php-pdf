<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill\Font;

/**
 * Parsed representation of a PDF simple font (Type1 / TrueType / MMType1)
 * for use when generating AcroForm field appearances with an embedded font.
 *
 * Width values are in 1000-em units (as stored in the PDF /Widths array).
 *
 * @internal
 */
final readonly class SimpleFontProgram
{
    /**
     * @param array<int, int> $codeWidths    byte code (0-255) -> width in 1000-em units
     * @param int             $missingWidth  width for codes absent from $codeWidths
     * @param array<int, int> $unicodeToCode unicode codepoint -> byte code
     */
    public function __construct(
        public array $codeWidths,
        public int $missingWidth,
        public array $unicodeToCode,
    ) {}
}
