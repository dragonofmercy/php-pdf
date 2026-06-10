<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Reader;

use DragonOfMercy\PhpPdf\Reader\DictReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class DictReaderTest extends TestCase
{
    public function testTypedExtraction(): void
    {
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Size'), PdfNumber::ofInt(12))
            ->withEntry(Name::of('Type'), Name::of('XRef'))
            ->withEntry(Name::of('W'), PdfArray::of(PdfNumber::ofInt(1), PdfNumber::ofInt(2)));

        self::assertSame(12, DictReader::int($dict, 'Size'));
        self::assertNull(DictReader::int($dict, 'Missing'));
        self::assertNull(DictReader::int($dict, 'Type'));
        self::assertSame('XRef', DictReader::name($dict, 'Type'));
        self::assertNull(DictReader::name($dict, 'Size'));
        self::assertSame([1, 2], DictReader::intList($dict, 'W'));
        self::assertNull(DictReader::intList($dict, 'Size'));
    }

    public function testResolveClosureIsApplied(): void
    {
        $dict = Dictionary::empty()->withEntry(Name::of('Length'), PdfReference::to(5, 0));
        $resolve = static fn (PdfObject $o): PdfObject => $o instanceof PdfReference ? PdfNumber::ofInt(77) : $o;
        self::assertSame(77, DictReader::int($dict, 'Length', $resolve));
    }
}
