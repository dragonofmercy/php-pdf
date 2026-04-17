<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Writer\Object;

use PhpPdf\Writer\Object\Dictionary;
use PhpPdf\Writer\Object\Name;
use PhpPdf\Writer\Object\PdfNumber;
use PHPUnit\Framework\TestCase;

final class DictionaryTest extends TestCase
{
    public function testEmptyDictionary(): void
    {
        self::assertSame('<< >>', Dictionary::empty()->toBytes());
    }

    public function testSingleEntry(): void
    {
        $dict = Dictionary::empty()->withEntry(Name::of('Type'), Name::of('Page'));
        self::assertSame('<< /Type /Page >>', $dict->toBytes());
    }

    public function testMultipleEntriesPreserveInsertionOrder(): void
    {
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Pages'))
            ->withEntry(Name::of('Count'), PdfNumber::ofInt(1));
        self::assertSame('<< /Type /Pages /Count 1 >>', $dict->toBytes());
    }

    public function testWithEntryReturnsNewInstance(): void
    {
        $original = Dictionary::empty();
        $modified = $original->withEntry(Name::of('X'), PdfNumber::ofInt(1));
        self::assertNotSame($original, $modified);
        self::assertSame('<< >>', $original->toBytes());
        self::assertSame('<< /X 1 >>', $modified->toBytes());
    }

    public function testOverwritingKeyReplacesAndKeepsPosition(): void
    {
        $dict = Dictionary::empty()
            ->withEntry(Name::of('A'), PdfNumber::ofInt(1))
            ->withEntry(Name::of('B'), PdfNumber::ofInt(2))
            ->withEntry(Name::of('A'), PdfNumber::ofInt(3));
        self::assertSame('<< /A 3 /B 2 >>', $dict->toBytes());
    }
}
