<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Markdown;

use DragonOfMercy\PhpPdf\Markdown\MarkdownStyle;
use DragonOfMercy\PhpPdf\{Color, Font};
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class MarkdownStyleTest extends TestCase
{
    public function testDefaultHasSixHeadingSizesDescending(): void
    {
        $s = MarkdownStyle::default();
        self::assertCount(6, $s->headingSizes);
        self::assertGreaterThan($s->headingSizes[6], $s->headingSizes[1]);
        self::assertNull($s->bodySize);
    }

    public function testWithersReturnNewInstance(): void
    {
        $s = MarkdownStyle::default();
        $s2 = $s->withLinkColor(Color::rgb(255, 0, 0));
        self::assertNotSame($s, $s2);
        self::assertNotEquals($s->linkColor, $s2->linkColor);
        self::assertEquals(Color::rgb(255, 0, 0), $s2->linkColor);
    }

    public function testRejectsNonPositiveHeadingSize(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Markdown heading size for level 1 must be positive, got 0');
        MarkdownStyle::default()->withHeadingSize(1, 0.0);
    }

    public function testCodeFontDefaultsToCourier(): void
    {
        self::assertSame(Font::courier()->pdfName(), MarkdownStyle::default()->codeFont->pdfName());
    }

    public function testWithHeadingSizeReplacesOnlyOneLevel(): void
    {
        $s = MarkdownStyle::default()->withHeadingSize(2, 30.0);
        self::assertSame(30.0, $s->headingSizes[2]);
        self::assertSame(24.0, $s->headingSizes[1]);
    }

    public function testWithBodySizeRejectsNonPositive(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Markdown body size must be positive, got 0');
        MarkdownStyle::default()->withBodySize(0.0);
    }

    public function testWithBlockQuoteBarKeepsCurrentOnNull(): void
    {
        $s = MarkdownStyle::default();
        $s2 = $s->withBlockQuoteBar(Color::rgb(10, 20, 30));
        self::assertSame($s->blockQuoteBarWidth, $s2->blockQuoteBarWidth);
        self::assertSame($s->blockQuoteIndent, $s2->blockQuoteIndent);
        self::assertEquals(Color::rgb(10, 20, 30), $s2->blockQuoteBarColor);
    }

    public function testRejectsNegativeParagraphSpacing(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Markdown paragraph spacing cannot be negative, got -1');
        MarkdownStyle::default()->withParagraphSpacing(-1.0);
    }
}
