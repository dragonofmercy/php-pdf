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
}
