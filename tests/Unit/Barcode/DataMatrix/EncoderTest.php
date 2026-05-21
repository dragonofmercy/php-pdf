<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\DataMatrix;

use DragonOfMercy\PhpPdf\Barcode\DataMatrix\Encoder;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class EncoderTest extends TestCase
{
    public function testEncodeSmallAsciiPicks10x10(): void
    {
        // "123456" -> 3 codewords -> 10x10 (3 data + 5 ec = 8 total).
        $result = Encoder::encode('123456');
        self::assertSame(10, $result->symbol->moduleRows);
        self::assertCount(8, $result->finalCodewords);
    }

    public function testPaddingFillsToSymbolCapacity(): void
    {
        // "A" -> 1 codeword. 10x10 needs 3 data codewords -> 2 pads.
        $result = Encoder::encode('A');
        self::assertSame(10, $result->symbol->moduleRows);
        self::assertCount(8, $result->finalCodewords);
        // Data codeword 'A' = 66 at position 0.
        self::assertSame(66, $result->finalCodewords[0]);
        // First pad = 129 at position 1.
        self::assertSame(129, $result->finalCodewords[1]);
    }

    public function testInterleavingForMultiBlockSymbol(): void
    {
        // 52x52 uses 2 RS blocks, 204 data + 84 EC = 288 final.
        // Use '~' (0x7E): outside the C40 basic set so Annex P stays in ASCII
        // (1 codeword per char), unlike 'A' which Annex P packs via C40.
        $data = str_repeat('~', 204);
        $result = Encoder::encode($data);
        self::assertSame(52, $result->symbol->moduleRows);
        self::assertCount(288, $result->finalCodewords);
    }

    public function testThrowsWhenInputTooLarge(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/DataMatrix data too large/');
        // 144x144 holds 1558 data codewords; 1559 '~' chars overflow
        // (each stays in ASCII = 1 codeword, see note on test above).
        Encoder::encode(str_repeat('~', 1559));
    }

    public function testEmptyInputThrows(): void
    {
        $this->expectException(PdfException::class);
        Encoder::encode('');
    }
}
