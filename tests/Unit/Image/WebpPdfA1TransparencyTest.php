<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Image\WebpDecoder;
use DragonOfMercy\PhpPdf\PdfA\PdfALevel;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class WebpPdfA1TransparencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (!WebpDecoder::isAvailable()) {
            self::markTestSkipped('No WebP decode backend.');
        }
    }

    public function testAlphaWebpRejectedUnderPdfA1(): void
    {
        $doc = new Document(Unit::PT);
        $doc->enablePdfA(PdfALevel::A1B);
        $page = $doc->addPage();
        $page->image(
            Image::fromFile(__DIR__ . '/../../Golden/assets/webp-lossless-alpha-4x4.webp'),
            x: 10, y: 10, w: 40, h: 40,
        );

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('PDF/A-1 forbids transparency');
        $doc->output();
    }

    public function testOpaqueWebpAllowedUnderPdfA1(): void
    {
        $doc = new Document(Unit::PT);
        $doc->enablePdfA(PdfALevel::A1B);
        $page = $doc->addPage();
        $page->image(
            Image::fromFile(__DIR__ . '/../../Golden/assets/webp-lossless-rgb-4x4.webp'),
            x: 10, y: 10, w: 40, h: 40,
        );

        // Opaque WebP must serialize without throwing.
        self::assertNotSame('', $doc->output());
    }
}
