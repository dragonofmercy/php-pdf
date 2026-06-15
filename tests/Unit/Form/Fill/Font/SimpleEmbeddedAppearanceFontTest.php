<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Fill\Font;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\Fill\Font\SimpleEmbeddedAppearanceFont;
use DragonOfMercy\PhpPdf\Form\Fill\Font\SimpleFontProgram;
use PHPUnit\Framework\TestCase;

final class SimpleEmbeddedAppearanceFontTest extends TestCase
{
    private function makeFont(): SimpleEmbeddedAppearanceFont
    {
        $program = new SimpleFontProgram(
            codeWidths: [65 => 700],
            missingWidth: 0,
            unicodeToCode: [65 => 65],
        );
        return new SimpleEmbeddedAppearanceFont($program, 'testField');
    }

    public function testMeasureWidthScalesBy1000AndSize(): void
    {
        $font = $this->makeFont();
        // 700/1000 * 10.0 = 7.0
        self::assertSame(7.0, $font->measureWidth('A', 10.0));
    }

    public function testEncodeShowOperandReturnsSingleByteLiteral(): void
    {
        $font = $this->makeFont();
        // 'A' has codepoint 65 -> byte code 65 -> chr(65) = 'A', no escaping needed
        self::assertSame('(A)', $font->encodeShowOperand('A'));
    }

    public function testMissingCharacterThrowsPdfException(): void
    {
        $font = $this->makeFont();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/testField/');
        $this->expectExceptionMessageMatches('/B/');
        $font->measureWidth('B', 10.0);
    }

    public function testEncodeShowOperandMissingCharacterThrowsPdfException(): void
    {
        $font = $this->makeFont();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/testField/');
        $this->expectExceptionMessageMatches('/B/');
        $font->encodeShowOperand('B');
    }
}
