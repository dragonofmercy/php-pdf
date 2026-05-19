<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Font\Custom\SfntReader;
use PHPUnit\Framework\TestCase;

final class SfntReaderTest extends TestCase
{
    public function testReadsUnsignedAndSignedIntegers(): void
    {
        $bytes = "\x12\x34" . "\xFF\xFE" . "\x00\x01\x00\x00";
        self::assertSame(0x1234, SfntReader::u16($bytes, 0));
        self::assertSame(-2, SfntReader::i16($bytes, 2));
        self::assertSame(0x00010000, SfntReader::u32($bytes, 4));
    }

    public function testParsesTableDirectoryAndLocaLongAndShort(): void
    {
        $offsetTable = "\x00\x01\x00\x00" . "\x00\x01" . "\x00\x10" . "\x00\x00" . "\x00\x00";
        $entry = 'maxp' . "\x00\x00\x00\x00" . pack('N', 28) . pack('N', 6);
        $maxp = "\x00\x00" . pack('n', 3) . "\x00\x00";
        $bytes = $offsetTable . $entry . $maxp;

        $dir = SfntReader::directory($bytes, 'ctx');
        self::assertSame(['offset' => 28, 'length' => 6], $dir['maxp']);

        $locaShort = pack('n4', 0, 5, 5, 9);
        self::assertSame([0, 10, 10, 18], SfntReader::loca($locaShort, 0, 0, 3));

        $locaLong = pack('N4', 0, 12, 40, 40);
        self::assertSame([0, 12, 40, 40], SfntReader::loca($locaLong, 0, 1, 3));
    }

    public function testTruncatedDirectoryThrows(): void
    {
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $this->expectExceptionMessage('sfnt table directory truncated in ctx');
        // numTables = 5 but no directory bytes follow
        SfntReader::directory("\x00\x01\x00\x00" . "\x00\x05" . "\x00\x00\x00\x00", 'ctx');
    }
}
