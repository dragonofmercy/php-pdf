<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Reader\Lexer;
use DragonOfMercy\PhpPdf\Reader\ObjectParser;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfBoolean;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use PHPUnit\Framework\TestCase;

final class ObjectParserTest extends TestCase
{
    private static function parse(string $pdf): PdfObject
    {
        return (new ObjectParser(new Lexer($pdf)))->parseObject();
    }

    public function testParsesScalars(): void
    {
        self::assertEquals(PdfNumber::ofInt(42), self::parse('42'));
        self::assertEquals(PdfNumber::ofFloat(-3.5), self::parse('-3.5'));
        self::assertEquals(Name::of('Type'), self::parse('/Type'));
        self::assertEquals(PdfString::of('hi'), self::parse('(hi)'));
        self::assertEquals(HexString::of('AB'), self::parse('<ab>'));
        self::assertEquals(PdfBoolean::true(), self::parse('true'));
        self::assertSame(PdfNull::instance(), self::parse('null'));
    }

    public function testParsesReference(): void
    {
        self::assertEquals(PdfReference::to(12, 0), self::parse('12 0 R'));
    }

    public function testTwoIntegersWithoutRAreJustNumbers(): void
    {
        $array = self::parse('[1 2 3]');
        self::assertInstanceOf(PdfArray::class, $array);
        self::assertCount(3, $array->elements());
        self::assertEquals(PdfNumber::ofInt(1), $array->elements()[0]);
    }

    public function testParsesMixedArrayWithReferences(): void
    {
        $array = self::parse('[/Name (str) 5 0 R 7]');
        self::assertInstanceOf(PdfArray::class, $array);
        $elements = $array->elements();
        self::assertEquals(Name::of('Name'), $elements[0]);
        self::assertEquals(PdfString::of('str'), $elements[1]);
        self::assertEquals(PdfReference::to(5, 0), $elements[2]);
        self::assertEquals(PdfNumber::ofInt(7), $elements[3]);
    }

    public function testParsesNestedDictionary(): void
    {
        $dict = self::parse('<< /Type /Page /Parent 3 0 R /Box [0 0 612 792] /Inner << /A 1 >> >>');
        self::assertInstanceOf(Dictionary::class, $dict);
        self::assertEquals(Name::of('Page'), $dict->get(Name::of('Type')));
        self::assertEquals(PdfReference::to(3, 0), $dict->get(Name::of('Parent')));
        $inner = $dict->get(Name::of('Inner'));
        self::assertInstanceOf(Dictionary::class, $inner);
        self::assertEquals(PdfNumber::ofInt(1), $inner->get(Name::of('A')));
    }

    public function testParsesIndirectObjectAt(): void
    {
        $pdf = "junk 7 0 obj\n<< /K (v) >>\nendobj\n";
        $parser = new ObjectParser(new Lexer($pdf));
        $object = $parser->parseIndirectObjectAt(5);
        self::assertSame(7, $object->objectNumber);
        self::assertSame(0, $object->generation);
        self::assertInstanceOf(Dictionary::class, $object->payload());
    }

    public function testIndirectObjectMissingEndobjIsTolerated(): void
    {
        $pdf = "7 0 obj 42 8 0 obj";
        $parser = new ObjectParser(new Lexer($pdf));
        $object = $parser->parseIndirectObjectAt(0);
        self::assertEquals(PdfNumber::ofInt(42), $object->payload());
    }

    public function testThrowsOnNonNameDictionaryKey(): void
    {
        $this->expectException(PdfParseException::class);
        $this->expectExceptionMessage('dictionary key');
        self::parse('<< 42 /V >>');
    }

    public function testThrowsOnUnexpectedKeyword(): void
    {
        $this->expectException(PdfParseException::class);
        self::parse('endobj');
    }
}
