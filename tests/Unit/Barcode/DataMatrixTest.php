<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\DataMatrix;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class DataMatrixTest extends TestCase
{
    public function testOfRejectsEmptyData(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/DataMatrix data must not be empty/');
        DataMatrix::of('');
    }

    public function testDefaultColorIsBlack(): void
    {
        $dm = DataMatrix::of('A');
        self::assertEquals(Color::rgb(0, 0, 0), $dm->color);
    }

    public function testWithColorReturnsNewInstance(): void
    {
        $dm = DataMatrix::of('A');
        $blue = $dm->withColor(Color::rgb(0, 0, 255));
        self::assertNotSame($dm, $blue);
        self::assertEquals(Color::rgb(0, 0, 255), $blue->color);
        self::assertEquals(Color::rgb(0, 0, 0), $dm->color, 'original is unchanged');
    }

    public function testDrawProducesValidPdf(): void
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $page->barcode(DataMatrix::of('Hello'), x: 10.0, y: 10.0, w: 20.0);
        $bytes = $doc->output();
        self::assertStringStartsWith('%PDF-1.', $bytes);
        self::assertStringEndsWith("%%EOF\n", $bytes);
    }

    public function testDrawRejectsNonSquareDimensions(): void
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/DataMatrix must be square/');
        $page->barcode(DataMatrix::of('A'), x: 10.0, y: 10.0, w: 20.0, h: 30.0);
    }

    public function testDrawRejectsNonPositiveWidth(): void
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/DataMatrix width must be positive/');
        $page->barcode(DataMatrix::of('A'), x: 10.0, y: 10.0, w: 0.0);
    }
}
