<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Document;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Outline\Link;
use PHPUnit\Framework\TestCase;

final class PageObjectsBuilderTest extends TestCase
{
    public function testPageWithLinkAnnotationSerializesAnnots(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->link(10, 10, 50, 10, Link::url('https://example.com'));
        $bytes = $doc->output();

        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertStringContainsString('/Annots', $bytes);
        self::assertStringContainsString('/Link', $bytes);
    }
}
