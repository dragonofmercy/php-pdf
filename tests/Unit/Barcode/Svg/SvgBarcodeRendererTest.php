<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Svg;

use DragonOfMercy\PhpPdf\Barcode\{AztecCode, Code128, DataMatrix, Pdf417, QrCode};
use DragonOfMercy\PhpPdf\Barcode\Svg\SvgBarcodeRenderer;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class SvgBarcodeRendererTest extends TestCase
{
    public function testRender2dEmitsWellFormedSvg(): void
    {
        $svg = (new SvgBarcodeRenderer())->render(QrCode::of('hello'), 200, 200);
        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        self::assertStringEndsWith('</svg>', $svg);
        self::assertStringContainsString('width="200"', $svg);
        self::assertStringContainsString('height="200"', $svg);
    }

    public function testBackgroundRectByDefault(): void
    {
        $svg = (new SvgBarcodeRenderer())->render(QrCode::of('hello'), 200, 200);
        self::assertStringContainsString('fill="#ffffff"', $svg);
    }

    public function testWithoutBackgroundOmitsWhiteRect(): void
    {
        $svg = (new SvgBarcodeRenderer())->withoutBackground()->render(QrCode::of('hello'), 200, 200);
        self::assertStringNotContainsString('fill="#ffffff"', $svg);
    }

    public function testForegroundColorEmitsAsRectFill(): void
    {
        $svg = (new SvgBarcodeRenderer())->render(QrCode::of('hello'), 200, 200);
        self::assertStringContainsString('fill="#000000"', $svg);
    }

    public function testQrViewBoxIncludesQuietZone(): void
    {
        $svg = (new SvgBarcodeRenderer())->render(QrCode::of('hello'), 200, 200);
        // V1 QR + 4-module quiet on each side = 29x29 module canvas.
        self::assertStringContainsString('viewBox="0 0 29 29"', $svg);
    }

    public function testDataMatrixRenders(): void
    {
        $svg = (new SvgBarcodeRenderer())->render(DataMatrix::of('HELLO'), 200, 200);
        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
    }

    public function testAztecRenders(): void
    {
        $svg = (new SvgBarcodeRenderer())->render(AztecCode::of('HELLO'), 200, 200);
        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
    }

    public function testRenderDataUriBase64Wraps(): void
    {
        $uri = SvgBarcodeRenderer::renderDataUri(QrCode::of('hello'), 200, 200);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $payload = substr($uri, strlen('data:image/svg+xml;base64,'));
        $decoded = base64_decode($payload, true);
        self::assertIsString($decoded);
        self::assertStringStartsWith('<svg', $decoded);
    }

    public function testZeroWidthThrows(): void
    {
        $this->expectException(PdfException::class);
        (new SvgBarcodeRenderer())->render(QrCode::of('hello'), 0, 200);
    }

    public function testZeroHeightThrows(): void
    {
        $this->expectException(PdfException::class);
        (new SvgBarcodeRenderer())->render(QrCode::of('hello'), 200, 0);
    }

    public function testLinear1dBarcodeThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('only supports 2D matrix barcodes');
        (new SvgBarcodeRenderer())->render(Code128::of('ABC'), 200, 80);
    }

    public function testPdf417Throws(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('PDF417');
        (new SvgBarcodeRenderer())->render(Pdf417::of('TEST'), 200, 100);
    }
}
