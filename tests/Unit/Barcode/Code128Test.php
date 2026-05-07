<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\Code128;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class Code128Test extends TestCase
{
    public function testOfAcceptsAsciiPrintable(): void
    {
        $code = Code128::of('PJJ123C');
        self::assertSame('PJJ123C', $code->data);
    }

    public function testOfRejectsEmpty(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Code 128 data must not be empty');
        Code128::of('');
    }

    public function testOfRejectsHighAscii(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('codepoint 233');
        Code128::of("c\xE9"); // Latin-1 byte for 'e' with acute accent
    }

    public function testOfAcceptsControlChars(): void
    {
        // \x07 (BEL) is a control char -> set A territory.
        $code = Code128::of("HE\x07LO");
        self::assertSame("HE\x07LO", $code->data);
    }

    public function testEncodeValuesPjj123c(): void
    {
        // "PJJ123C" -- documented sample. With auto-switching, all chars are ASCII
        // letters / digits in set B's range; algorithm should pick StartB and stay
        // in B (only 3 digits, not enough to justify switching to C).
        $values = Code128::of('PJJ123C')->encodeValuesForTest();
        // StartB = 104. P=48, J=42, J=42, 1=17, 2=18, 3=19, C=35.
        // Then checksum, then stop=106.
        self::assertSame(104, $values[0]);
        self::assertSame([48, 42, 42, 17, 18, 19, 35], array_slice($values, 1, 7));
        // Last value is Stop = 106.
        self::assertSame(106, end($values));
        // Length: start (1) + data (7) + checksum (1) + stop (1) = 10.
        self::assertCount(10, $values);
    }

    public function testEncodeValuesAllDigitsUsesStartC(): void
    {
        $values = Code128::of('1234567890')->encodeValuesForTest();
        // StartC = 105. Then 5 pairs (12, 34, 56, 78, 90) = values 12, 34, 56, 78, 90.
        self::assertSame(105, $values[0]);
        self::assertSame([12, 34, 56, 78, 90], array_slice($values, 1, 5));
        self::assertSame(106, end($values));
    }

    public function testEncodeModulesLengthIsExpected(): void
    {
        // PJJ123C: 1 start + 7 data + 1 checksum = 9 symbols * 11 modules + 13 stop
        // Per ISO 15417, total = 11*(symbols) + 13. With 9 symbols: 99 + 13 = 112.
        $modules = Code128::of('PJJ123C')->encodeModulesForTest();
        self::assertCount(11 * 9 + 13, $modules);
        // First module of every symbol must be a bar (true).
        self::assertTrue($modules[0]);
    }
}
