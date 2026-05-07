<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\Ean13;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class Ean13Test extends TestCase
{
    public function testOfWith13DigitsValidChecksum(): void
    {
        // ISBN 9780131103627 -- valid EAN-13.
        $code = Ean13::of('9780131103627');
        self::assertSame('9780131103627', $code->digits);
    }

    public function testOfWith12DigitsAutoComputesChecksum(): void
    {
        // 978013110362 + computed checksum 7
        $code = Ean13::of('978013110362');
        self::assertSame('9780131103627', $code->digits);
    }

    public function testOfWithInvalidChecksumThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('EAN-13 checksum invalid: expected 7, got 8');
        Ean13::of('9780131103628');
    }

    public function testOfWithNonDigitThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('EAN-13 expects digits only');
        Ean13::of('978013110362x');
    }

    public function testOfWithWrongLengthThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('EAN-13 expects 12 or 13 digits, got 5');
        Ean13::of('12345');
    }

    public function testOfUncheckedSkipsValidation(): void
    {
        $code = Ean13::ofUnchecked('xxx');
        self::assertSame('xxx', $code->digits);
    }

    public function testOfUncheckedWith12DigitsDoesNotAddChecksum(): void
    {
        // ofUnchecked is a pure pass-through; no computation happens.
        $code = Ean13::ofUnchecked('123456789012');
        self::assertSame('123456789012', $code->digits);
    }

    public function testEncodeModulesIsoExample(): void
    {
        // ISBN 9780131103627 -- known EAN-13.
        $code = Ean13::of('9780131103627');
        $modules = $code->encodeModulesForTest();
        // Total modules: 95.
        self::assertCount(95, $modules);
        // Left guard 101 at positions 0..2.
        self::assertSame([true, false, true], array_slice($modules, 0, 3));
        // Centre guard 01010 at positions 45..49 (3 + 6*7 = 45).
        self::assertSame([false, true, false, true, false], array_slice($modules, 45, 5));
        // Right guard 101 at positions 92..94.
        self::assertSame([true, false, true], array_slice($modules, 92, 3));
    }

    public function testEncodeModulesAllRightDigitsUseSetCStartsBar(): void
    {
        $code = Ean13::of('9780131103627');
        $modules = $code->encodeModulesForTest();
        // Each right-side digit is set C (e.g. digit "1" = 1100110, "2" = 1101100, etc.)
        // The first module of every right digit is a bar (true) per set C tables.
        $startOfRightDigits = 3 + 6 * 7 + 5; // 50
        for ($d = 0; $d < 6; $d++) {
            $idx = $startOfRightDigits + $d * 7;
            self::assertTrue($modules[$idx], "Right digit #{$d} should start with a bar");
        }
    }
}
