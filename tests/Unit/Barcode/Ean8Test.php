<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\Ean8;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class Ean8Test extends TestCase
{
    public function testOfWith8DigitsValidChecksum(): void
    {
        $code = Ean8::of('73513537');
        self::assertSame('73513537', $code->digits);
    }

    public function testOfWith7DigitsAutoChecksum(): void
    {
        $code = Ean8::of('7351353');
        self::assertSame('73513537', $code->digits);
    }

    public function testOfWithBadChecksumThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('EAN-8 checksum invalid');
        Ean8::of('73513538');
    }

    public function testOfWithWrongLengthThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('EAN-8 expects 7 or 8 digits');
        Ean8::of('123');
    }

    public function testEncodeModulesLength67(): void
    {
        $modules = Ean8::of('73513537')->encodeModulesForTest();
        self::assertCount(67, $modules);
        self::assertSame([true, false, true], array_slice($modules, 0, 3));
        self::assertSame([false, true, false, true, false], array_slice($modules, 31, 5));
        self::assertSame([true, false, true], array_slice($modules, 64, 3));
    }

    public function testDrawRendersAndIncludesHumanText(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->barcode(Ean8::of('73513537'), x: 10.0, y: 10.0, w: 81.0, h: 25.0);
        $bytes = $page->contentStream()->bytes();
        self::assertStringContainsString("\nq\n", $bytes);
        self::assertStringContainsString(' re', $bytes);
        self::assertStringContainsString("\nf\n", $bytes);
        self::assertStringContainsString('(7351)', $bytes);
        self::assertStringContainsString('(3537)', $bytes);
    }
}
