<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\Code93;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class Code93Test extends TestCase
{
    public function testValidDataAccepted(): void
    {
        self::assertSame('TEST93', Code93::of('TEST93')->data);
    }

    public function testInvalidCharacterThrowsWithIndex(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Code93: invalid character 'z' at index 1");
        Code93::of('Az');
    }

    public function testEmptyInputThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Code93 input cannot be empty');
        Code93::of('');
    }

    public function testCheckCharactersAppendedTwoSymbols(): void
    {
        $m = Code93::of('A')->encodeModulesForTest();
        self::assertCount(46, $m);
        self::assertTrue($m[0]);
        self::assertTrue($m[45]);
    }

    public function testWithColorAndWithoutTextImmutable(): void
    {
        $base = Code93::of('ABC');
        self::assertNotSame($base, $base->withColor(Color::rgb(9, 9, 9)));
        self::assertTrue($base->showText);
        self::assertFalse($base->withoutText()->showText);
    }

    public function testDrawWithoutHeightThrows(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Code93 requires explicit h (height)');
        $page->barcode(Code93::of('ABC'), x: 10.0, y: 10.0, w: 80.0);
    }

    public function testDrawIncludesHumanText(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->barcode(Code93::of('TEST93'), x: 10.0, y: 10.0, w: 120.0, h: 25.0);
        $bytes = $page->contentStream()->bytes();
        self::assertStringContainsString(' re', $bytes);
        self::assertStringContainsString('(TEST93)', $bytes);
    }
}
