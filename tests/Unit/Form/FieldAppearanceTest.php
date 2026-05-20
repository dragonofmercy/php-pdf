<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Form\FieldAppearance;
use DragonOfMercy\PhpPdf\TextAlign;
use PHPUnit\Framework\TestCase;

final class FieldAppearanceTest extends TestCase
{
    public function testDefaultConstruction(): void
    {
        $a = new FieldAppearance();
        self::assertNull($a->borderColor);
        self::assertNull($a->borderWidth);
        self::assertNull($a->backgroundColor);
        self::assertNull($a->textColor);
        self::assertNull($a->font);
        self::assertNull($a->fontSize);
        self::assertSame(TextAlign::LEFT, $a->align);
    }

    public function testCustomConstruction(): void
    {
        $a = new FieldAppearance(
            borderColor: Color::rgb(255, 0, 0),
            borderWidth: 2.0,
            backgroundColor: Color::rgb(240, 240, 240),
            textColor: Color::rgb(0, 0, 128),
            font: Font::courier(),
            fontSize: 12.0,
            align: TextAlign::CENTER,
        );
        self::assertNotNull($a->borderColor);
        self::assertSame(2.0, $a->borderWidth);
        self::assertNotNull($a->backgroundColor);
        self::assertNotNull($a->textColor);
        self::assertNotNull($a->font);
        self::assertSame(12.0, $a->fontSize);
        self::assertSame(TextAlign::CENTER, $a->align);
    }

    public function testBorderWidthZeroAllowed(): void
    {
        $a = new FieldAppearance(borderWidth: 0.0);
        self::assertSame(0.0, $a->borderWidth);
    }

    public function testBorderWidthNegativeThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Field appearance borderWidth cannot be negative, got -1');
        new FieldAppearance(borderWidth: -1.0);
    }

    public function testFontSizeZeroThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Field appearance fontSize must be positive, got 0');
        new FieldAppearance(fontSize: 0.0);
    }

    public function testFontSizeNegativeThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Field appearance fontSize must be positive, got -5');
        new FieldAppearance(fontSize: -5.0);
    }
}
