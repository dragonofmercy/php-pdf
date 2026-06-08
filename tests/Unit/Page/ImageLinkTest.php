<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Outline\Link;
use PHPUnit\Framework\TestCase;

final class ImageLinkTest extends TestCase
{
    private const string PNG = __DIR__ . '/../../Golden/assets/png-opaque-rgb-24x12.png';

    public function testTaggedImageLinkEmitsLinkFigureObjr(): void
    {
        $doc = new Document();
        $doc->enableTagging('en-US');
        $page = $doc->addPage();
        $page->image(self::PNG, x: 10, y: 10, w: 30, h: 30, alt: 'Logo', link: Link::url('https://example.com'));
        $pdf = $doc->output();

        self::assertMatchesRegularExpression('#/S\s*/Link\b#', $pdf, 'a <Link> structure element');
        self::assertMatchesRegularExpression('#/S\s*/Figure\b#', $pdf, 'a <Figure> structure element');
        self::assertMatchesRegularExpression('#/Type\s*/OBJR#', $pdf, 'an OBJR leaf');
        self::assertMatchesRegularExpression('#/Subtype\s*/Link#', $pdf, 'a link annotation');
        self::assertMatchesRegularExpression('#/StructParent\b#', $pdf, '/StructParent on the annotation');
        self::assertMatchesRegularExpression('#/F\s+4\b#', $pdf, 'the Print flag /F 4');
    }

    public function testLinkAltWithoutLinkThrows(): void
    {
        $page = (new Document())->addPage();
        $this->expectException(PdfException::class);
        $page->image(self::PNG, x: 10, y: 10, w: 30, h: 30, linkAlt: 'x');
    }

    public function testLinkWithDecorativeThrows(): void
    {
        $page = (new Document())->addPage();
        $this->expectException(PdfException::class);
        $page->image(self::PNG, x: 10, y: 10, w: 30, h: 30, decorative: true, link: Link::url('https://example.com'));
    }

    public function testTaggingOffImageLinkEmitsPlainAnnotation(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->image(self::PNG, x: 10, y: 10, w: 30, h: 30, link: Link::url('https://example.com'));
        $pdf = $doc->output();

        self::assertDoesNotMatchRegularExpression('#/S\s*/Link\b#', $pdf, 'no structure element off-path');
        self::assertMatchesRegularExpression('#/Subtype\s*/Link#', $pdf, 'a plain link annotation is still emitted');
    }
}
