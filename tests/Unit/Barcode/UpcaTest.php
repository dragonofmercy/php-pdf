<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\Upca;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class UpcaTest extends TestCase
{
    public function testElevenDigitsAppendsChecksum(): void
    {
        self::assertSame('036000291452', Upca::of('03600029145')->digits);
    }

    public function testTwelveDigitsValidChecksumAccepted(): void
    {
        self::assertSame('036000291452', Upca::of('036000291452')->digits);
    }

    public function testTwelveDigitsBadChecksumThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('UPC-A checksum invalid: expected 2, got 9');
        Upca::of('036000291459');
    }

    public function testNonDigitThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('UPC-A expects digits only');
        Upca::of('03600029145X');
    }

    public function testWrongLengthThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('UPC-A expects 11 or 12 digits, got 5');
        Upca::of('12345');
    }

    public function testEncodeModulesStructure(): void
    {
        $m = Upca::of('036000291452')->encodeModulesForTest();
        self::assertCount(95, $m);
        self::assertSame([true, false, true], array_slice($m, 0, 3));
        self::assertSame([false, true, false, true, false], array_slice($m, 45, 5));
        self::assertSame([true, false, true], array_slice($m, 92, 3));
    }

    public function testWithColorAndWithoutTextAreImmutable(): void
    {
        $base = Upca::of('036000291452');
        $colored = $base->withColor(Color::rgb(1, 2, 3));
        $noText = $base->withoutText();
        self::assertNotSame($base, $colored);
        self::assertNotSame($base, $noText);
        self::assertTrue($base->showText);
        self::assertFalse($noText->showText);
    }

    public function testDrawWithoutHeightThrows(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Upca requires explicit h (height)');
        $page->barcode(Upca::of('036000291452'), x: 10.0, y: 10.0, w: 80.0);
    }

    public function testOfUncheckedSkipsValidation(): void
    {
        // ofUnchecked stores the literal input without digit/length/checksum checks
        $code = Upca::ofUnchecked('not-12-digits');
        self::assertSame('not-12-digits', $code->digits);
    }

    public function testDrawIncludesHumanTextDigits(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->barcode(Upca::of('036000291452'), x: 10.0, y: 10.0, w: 90.0, h: 25.0);
        $bytes = $page->contentStream()->bytes();
        self::assertStringContainsString("\nq\n", $bytes);
        self::assertStringContainsString(' re', $bytes);
        self::assertStringContainsString('(0)', $bytes);
        self::assertStringContainsString('(36000)', $bytes);
        self::assertStringContainsString('(29145)', $bytes);
        self::assertStringContainsString('(2)', $bytes);
    }
}
