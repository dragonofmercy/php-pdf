<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\{Code128, Code39, Code93, Ean13, Ean8, Itf, Upca};
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class WidthForModuleTest extends TestCase
{
    public function testEan8WidthForModuleUsesTotalModulesTimesSize(): void
    {
        // EAN-8 total modules (quiet + bars) is fixed at 81 per ISO/IEC 15420.
        $bc = Ean8::of('1234567');
        self::assertSame(81 * 0.3, $bc->widthForModule(0.3));
    }

    public function testEan13WidthForModuleUsesTotalModulesTimesSize(): void
    {
        // EAN-13 total modules is fixed at 113 (11 left quiet + 95 bars + 7 right quiet).
        $bc = Ean13::of('123456789012');
        self::assertSame(113 * 0.3, $bc->widthForModule(0.3));
    }

    public function testUpcaWidthForModuleUsesTotalModulesTimesSize(): void
    {
        // UPC-A total modules is fixed at 113 (9 + 95 + 9).
        $bc = Upca::of('12345678901');
        self::assertSame(113 * 0.3, $bc->widthForModule(0.3));
    }

    public function testItfWidthForModuleAccountsForEncodedLength(): void
    {
        // ITF: start (4) + 9 * (digits / 2) * 2 + stop (5) = 9 * digits + 9 bar modules,
        // plus 2 * 10 quiet modules. For 8 digits: 9*8 + 9 + 20 = 101 total modules.
        $bc = Itf::of('12345678');
        self::assertSame(101 * 0.3, $bc->widthForModule(0.3));
    }

    public function testCode39WidthForModuleAccountsForEncodedLength(): void
    {
        // Code 39 module count depends on the encoded length; cross-check via
        // the test accessor encodeModulesForTest() to avoid hardcoding the
        // start/stop/inter-char gap math here.
        $bc = Code39::of('A');
        $expectedBars = count($bc->encodeModulesForTest());
        self::assertSame(($expectedBars + 20) * 0.3, $bc->widthForModule(0.3));
    }

    public function testCode93WidthForModuleAccountsForEncodedLength(): void
    {
        // Code 93: 9 modules per symbol + 2 check chars + termination bar; quiet 10+10.
        // Cross-check via encodeModulesForTest().
        $bc = Code93::of('A');
        $expectedBars = count($bc->encodeModulesForTest());
        self::assertSame(($expectedBars + 20) * 0.3, $bc->widthForModule(0.3));
    }

    public function testCode128WidthForModuleAccountsForEncodedLength(): void
    {
        // Code 128: 11 modules per value + 13-module stop pattern; quiet 10+10.
        // Cross-check via encodeModulesForTest().
        $bc = Code128::of('ABC123');
        $expectedBars = count($bc->encodeModulesForTest());
        self::assertSame(($expectedBars + 20) * 0.3, $bc->widthForModule(0.3));
    }
}
