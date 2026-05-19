<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\Itf;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class ItfTest extends TestCase
{
    public function testEvenDigitsAccepted(): void
    {
        self::assertSame('1234', Itf::of('1234')->digits);
    }

    public function testOddLengthThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('ITF expects an even number of digits, got 3');
        Itf::of('123');
    }

    public function testNonDigitThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('ITF expects digits only');
        Itf::of('12X4');
    }

    public function testGtin14ThirteenAppendsChecksum(): void
    {
        self::assertSame('12345678901231', Itf::ofGtin14('1234567890123')->digits);
    }

    public function testGtin14FourteenBadChecksumThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('ITF GTIN-14 checksum invalid: expected 1, got 9');
        Itf::ofGtin14('12345678901239');
    }

    public function testGtin14WrongLengthThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('ITF GTIN-14 expects 13 or 14 digits, got 4');
        Itf::ofGtin14('1234');
    }

    public function testStartPatternModulesAreNarrow1010(): void
    {
        $m = Itf::of('1234')->encodeModulesForTest();
        self::assertSame([true, false, true, false], array_slice($m, 0, 4));
    }

    public function testWithColorAndWithoutTextImmutable(): void
    {
        $base = Itf::of('1234');
        self::assertNotSame($base, $base->withColor(Color::rgb(1, 1, 1)));
        self::assertTrue($base->showText);
        self::assertFalse($base->withoutText()->showText);
    }

    public function testDrawWithoutHeightThrows(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Itf requires explicit h (height)');
        $page->barcode(Itf::of('1234'), x: 10.0, y: 10.0, w: 80.0);
    }

    public function testDrawIncludesHumanText(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->barcode(Itf::of('12345670'), x: 10.0, y: 10.0, w: 100.0, h: 25.0);
        $bytes = $page->contentStream()->bytes();
        self::assertStringContainsString(' re', $bytes);
        self::assertStringContainsString('(12345670)', $bytes);
    }
}
