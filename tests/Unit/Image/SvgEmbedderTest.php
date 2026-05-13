<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Image\ImageEmbedder;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgEmbedderTest extends TestCase
{
    public function testEmbedSvgProducesOneFormXObject(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="red"/></svg>';
        $img = Image::fromBytes($svg);
        $objs = (new ImageEmbedder())->embed($img, 100);
        self::assertCount(1, $objs);
        $bytes = $objs[0]->toBytes();
        self::assertStringContainsString('/Type /XObject', $bytes);
        self::assertStringContainsString('/Subtype /Form', $bytes);
        self::assertStringContainsString('/BBox [0 0 1 1]', $bytes);
        self::assertStringContainsString('/Resources', $bytes);
        self::assertStringContainsString('/Filter /FlateDecode', $bytes);
    }

    public function testEmbedSvgWithOpacityIncludesExtGState(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="red" fill-opacity="0.5"/></svg>';
        $img = Image::fromBytes($svg);
        $objs = (new ImageEmbedder())->embed($img, 100);
        $bytes = $objs[0]->toBytes();
        self::assertStringContainsString('/ExtGState', $bytes);
        self::assertStringContainsString('/Gs0', $bytes);
        self::assertStringContainsString('/ca', $bytes);
        self::assertStringContainsString('/CA', $bytes);
    }

    public function testEmbedSvgWithoutOpacityHasNoExtGState(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="red"/></svg>';
        $img = Image::fromBytes($svg);
        $objs = (new ImageEmbedder())->embed($img, 100);
        $bytes = $objs[0]->toBytes();
        self::assertStringNotContainsString('/ExtGState', $bytes);
    }

    public function testObjectCountSvgIsOne(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';
        $img = Image::fromBytes($svg);
        self::assertSame(1, ImageEmbedder::objectCount($img));
    }

    public function testEmbedSvgIntoDocumentEndToEnd(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="6" fill="blue"/></svg>';
        $img = Image::fromBytes($svg);
        $page->image($img, x: 10.0, y: 10.0, w: 50.0, h: 50.0);
        $bytes = $doc->output();
        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertGreaterThan(500, strlen($bytes));
    }
}
