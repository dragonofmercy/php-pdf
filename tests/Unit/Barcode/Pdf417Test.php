<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\Pdf417;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class Pdf417Test extends TestCase
{
    public function testOfRejectsEmpty(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/PDF417 data must not be empty/');
        Pdf417::of('');
    }

    public function testWithErrorCorrectionRejectsOutOfRange(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/error-correction level must be 0-8/');
        Pdf417::of('x')->withErrorCorrection(9);
    }

    public function testWithColumnsRejectsOutOfRange(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/column count must be 1-30/');
        Pdf417::of('x')->withColumns(31);
    }

    public function testDefaultColorBlackAndImmutability(): void
    {
        $a = Pdf417::of('x');
        $b = $a->withColor(Color::rgb(0, 0, 255));
        self::assertEquals(Color::rgb(0, 0, 0), $a->color);
        self::assertNotSame($a, $b);
        self::assertEquals(Color::rgb(0, 0, 255), $b->color);
    }

    public function testDrawProducesValidPdf(): void
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $page->barcode(Pdf417::of('PDF417 sample 12345'), x: 10.0, y: 10.0, w: 90.0);
        $bytes = $doc->output();
        self::assertStringStartsWith('%PDF-1.', $bytes);
        self::assertStringEndsWith("%%EOF\n", $bytes);
    }

    public function testDrawRejectsNonPositiveWidth(): void
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/PDF417 width must be positive/');
        $page->barcode(Pdf417::of('x'), x: 10.0, y: 10.0, w: 0.0);
    }

    public function testDrawRejectsNonPositiveHeight(): void
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/PDF417 height must be positive/');
        $page->barcode(Pdf417::of('x'), x: 10.0, y: 10.0, w: 90.0, h: 0.0);
    }
}
