<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\Code39;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class Code39Test extends TestCase
{
    public function testValidDataAccepted(): void
    {
        self::assertSame('CODE 39', Code39::of('CODE 39')->data);
    }

    public function testInvalidCharacterThrowsWithIndex(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Code39: invalid character 'a' at index 2");
        Code39::of('CXa');
    }

    public function testStarInInputRejected(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Code39: invalid character '*' at index 0");
        Code39::of('*AB*');
    }

    public function testEmptyInputThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Code39 input cannot be empty');
        Code39::of('');
    }

    public function testStartStopFramingPresentInModules(): void
    {
        $m = Code39::of('A')->encodeModulesForTest();
        self::assertCount(47, $m); // * + gap + A + gap + * : 3 symbols * 15 modules + 2 one-module gaps
        self::assertTrue($m[0]);
        self::assertTrue($m[count($m) - 1]);
    }

    public function testCheckDigitOptInChangesEncoding(): void
    {
        $base = Code39::of('CODE39');
        $checked = $base->withCheckDigit();
        self::assertFalse($base->hasCheckDigit);
        self::assertTrue($checked->hasCheckDigit);
        self::assertNotSame(
            $base->encodeModulesForTest(),
            $checked->encodeModulesForTest(),
        );
    }

    public function testWithColorAndWithoutTextImmutable(): void
    {
        $base = Code39::of('ABC');
        self::assertNotSame($base, $base->withColor(Color::rgb(1, 2, 3)));
        self::assertTrue($base->showText);
        self::assertFalse($base->withoutText()->showText);
    }

    public function testDrawWithoutHeightThrows(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Code39 requires explicit h (height)');
        $page->barcode(Code39::of('ABC'), x: 10.0, y: 10.0, w: 80.0);
    }

    public function testDrawIncludesHumanText(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->barcode(Code39::of('CODE 39'), x: 10.0, y: 10.0, w: 120.0, h: 25.0);
        $bytes = $page->contentStream()->bytes();
        self::assertStringContainsString(' re', $bytes);
        self::assertStringContainsString('(CODE 39)', $bytes);
    }
}
