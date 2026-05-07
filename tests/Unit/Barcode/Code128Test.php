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
}
