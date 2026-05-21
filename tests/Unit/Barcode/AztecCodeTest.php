<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\AztecCode;
use DragonOfMercy\PhpPdf\Barcode\AztecEc;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class AztecCodeTest extends TestCase
{
    public function testOfRejectsEmptyString(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Aztec code data must not be empty');
        AztecCode::of('');
    }

    public function testDefaultsAreMediumEcAndBlack(): void
    {
        $code = AztecCode::of('HELLO');
        self::assertSame(AztecEc::MEDIUM, $code->errorCorrection);
        self::assertEquals(Color::rgb(0, 0, 0), $code->color);
    }

    public function testWithErrorCorrectionIsImmutable(): void
    {
        $a = AztecCode::of('HELLO');
        $b = $a->withErrorCorrection(AztecEc::HIGH);
        self::assertSame(AztecEc::MEDIUM, $a->errorCorrection);
        self::assertSame(AztecEc::HIGH, $b->errorCorrection);
        self::assertNotSame($a, $b);
    }

    public function testWithColorIsImmutable(): void
    {
        $a = AztecCode::of('HELLO');
        $red = Color::rgb(255, 0, 0);
        $b = $a->withColor($red);
        self::assertNotSame($a, $b);
        self::assertEquals($red, $b->color);
        self::assertEquals(Color::rgb(0, 0, 0), $a->color);
    }

    public function testDrawProducesQAndF(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->barcode(AztecCode::of('HELLO'), x: 20.0, y: 20.0, w: 30.0);
        $bytes = $page->contentStream()->bytes();
        self::assertStringContainsString("\nq\n", $bytes);
        self::assertStringContainsString(' re', $bytes);
        self::assertStringContainsString("\nf\n", $bytes);
        self::assertStringContainsString("\nQ\n", $bytes);
    }

    public function testDrawWithEqualHDoesNotThrow(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->barcode(AztecCode::of('HELLO'), x: 0.0, y: 0.0, w: 30.0, h: 30.0);
        self::assertNotEmpty($doc->output());
    }

    public function testDrawNonSquareThrows(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Aztec code must be square: w (30) != h (25)');
        $page->barcode(AztecCode::of('HELLO'), x: 0.0, y: 0.0, w: 30.0, h: 25.0);
    }

    public function testDrawZeroWidthThrows(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Aztec code width must be positive, got 0');
        $page->barcode(AztecCode::of('HELLO'), x: 0.0, y: 0.0, w: 0.0);
    }

    public function testOutputIsNonEmpty(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->barcode(AztecCode::of('HELLO'), x: 20.0, y: 20.0, w: 30.0);
        self::assertNotEmpty($doc->output());
    }

    public function testCustomColorIsEmitted(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->barcode(
            AztecCode::of('HELLO')->withColor(Color::hex('#003366')),
            x: 10.0, y: 10.0, w: 30.0,
        );
        $bytes = $page->contentStream()->bytes();
        // Colour 0x00, 0x33, 0x66 -> approximately 0, 0.2, 0.4.
        self::assertStringContainsString('0 0.2 0.4 rg', $bytes);
    }
}
