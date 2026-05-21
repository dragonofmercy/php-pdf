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
}
