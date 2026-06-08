<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Outline\Link;
use PHPUnit\Framework\TestCase;

final class RegisterTaggedMarkdownLinkTest extends TestCase
{
    public function testRegistersTaggedAnnotationWithStructParentAndContents(): void
    {
        $doc = new Document();
        $doc->enableTagging('en-US');
        $page = $doc->addPage();

        $annot = $page->registerTaggedMarkdownLink(10.0, 20.0, 30.0, 8.0, Link::url('https://example.com'), 'example');

        self::assertTrue($annot->isTagged());
        self::assertSame('example', $annot->contents);
        self::assertContains($annot, $page->getLinkAnnotations());
    }

    public function testEachCallAddsOneAnnotation(): void
    {
        $doc = new Document();
        $doc->enableTagging('en-US');
        $page = $doc->addPage();

        $page->registerTaggedMarkdownLink(0.0, 0.0, 10.0, 5.0, Link::url('https://example.com'), 'link text');
        $page->registerTaggedMarkdownLink(0.0, 10.0, 10.0, 5.0, Link::url('https://example.org'), 'other link');

        self::assertCount(2, $page->getLinkAnnotations());
    }

    public function testRejectsNonPositiveDimensions(): void
    {
        $doc = new Document();
        $doc->enableTagging('en-US');
        $page = $doc->addPage();

        $this->expectException(PdfException::class);
        $page->registerTaggedMarkdownLink(0.0, 0.0, 0.0, 5.0, Link::url('https://example.com'), 'bad link');
    }
}
