<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Reader\ObjectStreamReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use PHPUnit\Framework\TestCase;

final class ObjectStreamReaderTest extends TestCase
{
    /** "12 -> (a string), 14 -> << /T /V >>, 20 -> 42" packed as an ObjStm payload. */
    private static function reader(): ObjectStreamReader
    {
        $objects = "(a string)\n<< /T /V >>\n42";
        // offsets: object 12 at 0, object 14 at 11, object 20 at 23
        $header = '12 0 14 11 20 23 ';
        return new ObjectStreamReader($header . $objects, count: 3, first: strlen($header));
    }

    public function testReadsObjectsByIndex(): void
    {
        $reader = self::reader();
        self::assertEquals(PdfString::of('a string'), $reader->objectAt(0));
        $dict = $reader->objectAt(1);
        self::assertInstanceOf(Dictionary::class, $dict);
        self::assertEquals(Name::of('V'), $dict->get(Name::of('T')));
        self::assertEquals(PdfNumber::ofInt(42), $reader->objectAt(2));
    }

    public function testExposesObjectNumbers(): void
    {
        $reader = self::reader();
        self::assertSame(12, $reader->objectNumberAt(0));
        self::assertSame(14, $reader->objectNumberAt(1));
        self::assertSame(20, $reader->objectNumberAt(2));
    }

    public function testIndexOutOfRangeThrows(): void
    {
        $this->expectException(PdfParseException::class);
        $this->expectExceptionMessage('index 3');
        self::reader()->objectAt(3);
    }

    public function testMalformedHeaderThrows(): void
    {
        $reader = new ObjectStreamReader('12 /NotANumber x', count: 1, first: 16);
        $this->expectException(PdfParseException::class);
        $reader->objectAt(0);
    }
}
