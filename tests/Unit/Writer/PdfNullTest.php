<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Writer;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfBoolean;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use PHPUnit\Framework\TestCase;

final class PdfNullTest extends TestCase
{
    public function testNullSerializesAsKeyword(): void
    {
        self::assertSame('null', PdfNull::instance()->toBytes());
    }

    public function testInstanceIsShared(): void
    {
        self::assertSame(PdfNull::instance(), PdfNull::instance());
    }

    public function testNumberExposesValue(): void
    {
        self::assertSame(42, PdfNumber::ofInt(42)->value());
        self::assertSame(2.5, PdfNumber::ofFloat(2.5)->value());
    }

    public function testNameExposesValue(): void
    {
        self::assertSame('XRef', Name::of('XRef')->value());
    }

    public function testBooleanExposesValue(): void
    {
        self::assertTrue(PdfBoolean::true()->value());
        self::assertFalse(PdfBoolean::false()->value());
    }

    public function testParseExceptionIsAPdfException(): void
    {
        self::assertInstanceOf(PdfException::class, new PdfParseException('bad byte at offset 12'));
    }
}
