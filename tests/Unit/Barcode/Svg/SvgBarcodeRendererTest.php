<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Svg;

use DragonOfMercy\PhpPdf\Barcode\{Code128, QrCode};
use DragonOfMercy\PhpPdf\Barcode\Svg\SvgBarcodeRenderer;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class SvgBarcodeRendererTest extends TestCase
{
    public function testRender1dEmitsWellFormedSvg(): void
    {
        $svg = (new SvgBarcodeRenderer())->render(Code128::of('ABC'), 200, 80);
        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        self::assertStringEndsWith('</svg>', $svg);
        self::assertStringContainsString('viewBox=', $svg);
        self::assertStringContainsString('width="200"', $svg);
        self::assertStringContainsString('height="80"', $svg);
    }

    public function testBackgroundRectByDefault(): void
    {
        $svg = (new SvgBarcodeRenderer())->render(Code128::of('ABC'), 200, 80);
        self::assertStringContainsString('fill="#ffffff"', $svg);
    }

    public function testWithoutBackgroundOmitsWhiteRect(): void
    {
        $svg = (new SvgBarcodeRenderer())->withoutBackground()->render(Code128::of('ABC'), 200, 80);
        self::assertStringNotContainsString('fill="#ffffff"', $svg);
    }

    public function testForegroundColorEmitsAsRectFill(): void
    {
        $svg = (new SvgBarcodeRenderer())->render(Code128::of('ABC'), 200, 80);
        self::assertStringContainsString('fill="#000000"', $svg);
    }

    public function testRender2dEmitsSquareViewBox(): void
    {
        $svg = (new SvgBarcodeRenderer())->render(QrCode::of('hello'), 200, 200);
        // V1 QR + 4-module quiet on each side = 29x29 module canvas.
        self::assertStringContainsString('viewBox="0 0 29 29"', $svg);
    }

    public function testHumanTextEmittedAsTextElement(): void
    {
        $svg = (new SvgBarcodeRenderer())->render(Code128::of('ABC'), 200, 80);
        self::assertStringContainsString('<text', $svg);
        self::assertStringContainsString('text-anchor="middle"', $svg);
        self::assertStringContainsString('font-family="Helvetica"', $svg);
        self::assertStringContainsString('>ABC</text>', $svg);
    }

    public function testVerticalOrientationWrapsInRotateGroup(): void
    {
        $svg = (new SvgBarcodeRenderer())->render(Code128::of('ABC')->vertical(), 80, 200);
        self::assertStringContainsString('<g transform="rotate(-90)', $svg);
        self::assertStringContainsString('</g>', $svg);
    }

    public function testRenderDataUriBase64Wraps(): void
    {
        $uri = SvgBarcodeRenderer::renderDataUri(Code128::of('ABC'), 200, 80);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $payload = substr($uri, strlen('data:image/svg+xml;base64,'));
        $decoded = base64_decode($payload, true);
        self::assertIsString($decoded);
        self::assertStringStartsWith('<svg', $decoded);
    }

    public function testZeroWidthThrows(): void
    {
        $this->expectException(PdfException::class);
        (new SvgBarcodeRenderer())->render(Code128::of('ABC'), 0, 80);
    }

    public function testZeroHeightThrows(): void
    {
        $this->expectException(PdfException::class);
        (new SvgBarcodeRenderer())->render(Code128::of('ABC'), 200, 0);
    }
}
