<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\Linear1d;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class Linear1dTest extends TestCase
{
    public function testNullHeightThrows(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('TestFmt requires explicit h (height)');
        Linear1d::draw($page, 0.0, 0.0, 50.0, null, [true, false, true], 10, Color::rgb(0, 0, 0), null, 'TestFmt');
    }

    public function testRendersBarsWrappedAndNoTextWhenHumanTextNull(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        Linear1d::draw($page, 10.0, 10.0, 60.0, 20.0, [true, false, true], 10, Color::rgb(0, 0, 0), null, 'TestFmt');
        $bytes = $page->contentStream()->bytes();
        self::assertStringContainsString("\nq\n", $bytes);
        self::assertStringContainsString(' re', $bytes);
        self::assertStringContainsString("\nf\n", $bytes);
        self::assertStringNotContainsString('BT', $bytes);
    }

    public function testRendersHumanTextWhenProvided(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        Linear1d::draw($page, 10.0, 10.0, 80.0, 25.0, [true, false, true], 10, Color::rgb(0, 0, 0), 'ABC', 'TestFmt');
        $bytes = $page->contentStream()->bytes();
        self::assertStringContainsString('(ABC)', $bytes);
    }

    public function testEmptyHumanTextBehavesLikeNoText(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        Linear1d::draw($page, 10.0, 10.0, 60.0, 20.0, [true, false, true], 10, Color::rgb(0, 0, 0), '', 'TestFmt');
        $bytes = $page->contentStream()->bytes();
        self::assertStringContainsString(' re', $bytes);
        self::assertStringNotContainsString('BT', $bytes);
    }
}
