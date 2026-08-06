<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Image\WebpDecoder;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class WebpImageGoldenTest extends TestCase
{
    protected function setUp(): void
    {
        if (!WebpDecoder::isAvailable()) {
            self::markTestSkipped('No WebP decode backend; byte-identity fixture is backend-dependent.');
        }
    }

    public function testOpaqueWebpMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/image/webp-opaque.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            $this->buildPdfBytes('webp-lossless-rgb-4x4.webp'),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testAlphaWebpMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/image/webp-alpha.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            $this->buildPdfBytes('webp-lossless-alpha-4x4.webp'),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testWebpOpaquePassesQpdfCheck(): void
    {
        $this->assertQpdfCheck(__DIR__ . '/fixtures/image/webp-opaque.pdf');
    }

    public function testWebpAlphaPassesQpdfCheck(): void
    {
        $this->assertQpdfCheck(__DIR__ . '/fixtures/image/webp-alpha.pdf');
    }

    private function buildPdfBytes(string $asset): string
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->image(Image::fromFile(__DIR__ . '/assets/' . $asset), x: 50, y: 50, w: 80, h: 80);
        return $doc->output();
    }

    private function assertQpdfCheck(string $path): void
    {
        Qpdf::assertCheck($path);
    }
}
