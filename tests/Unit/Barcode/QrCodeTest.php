<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\ErrorCorrection;
use DragonOfMercy\PhpPdf\Barcode\QrCode;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class QrCodeTest extends TestCase
{
    public function testOfRejectsEmpty(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('QR code data must not be empty');
        QrCode::of('');
    }

    public function testOfDefaultsToErrorCorrectionM(): void
    {
        $qr = QrCode::of('hello');
        self::assertSame(ErrorCorrection::M, $qr->errorCorrection);
    }

    public function testWithErrorCorrectionReturnsNewInstance(): void
    {
        $qr = QrCode::of('hello');
        $h = $qr->withErrorCorrection(ErrorCorrection::H);
        self::assertNotSame($qr, $h);
        self::assertSame(ErrorCorrection::H, $h->errorCorrection);
    }

    public function testDrawProducesQAndF(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->barcode(QrCode::of('https://example.com'), x: 10.0, y: 10.0, w: 50.0);
        $bytes = $page->contentStream()->bytes();
        self::assertStringContainsString("\nq\n", $bytes);
        self::assertStringContainsString(' re', $bytes);
        self::assertStringContainsString("\nf\n", $bytes);
        self::assertStringContainsString("\nQ\n", $bytes);
    }

    public function testDrawCustomColorEmitted(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->barcode(
            QrCode::of('hello')->withColor(Color::hex('#003366')),
            x: 10.0, y: 10.0, w: 30.0,
        );
        $bytes = $page->contentStream()->bytes();
        // Colour 0x00, 0x33, 0x66 -- approximately 0, 0.2, 0.4
        self::assertStringContainsString('0 0.2 0.4 rg', $bytes);
    }

    public function testDrawSquareWhenHEqualsW(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->barcode(QrCode::of('hello'), x: 0.0, y: 0.0, w: 30.0, h: 30.0);
        // No exception -> ok.
        self::assertNotEmpty($doc->output());
    }

    public function testDrawNonSquareThrows(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('QR code must be square: w (30) != h (25)');
        $page->barcode(QrCode::of('hello'), x: 0.0, y: 0.0, w: 30.0, h: 25.0);
    }
}
