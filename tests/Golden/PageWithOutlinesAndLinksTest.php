<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Outline\Destination;
use DragonOfMercy\PhpPdf\Outline\Link;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class PageWithOutlinesAndLinksTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/page/outlines-and-links.pdf';

    public function testPageWithOutlinesAndLinksMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(self::FIXTURE);
        self::assertIsString($expected, 'Golden fixture missing - regenerate with tests/Golden/regenerate.php');
        self::assertSame(
            $expected,
            $this->buildDocument()->output(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPageWithOutlinesAndLinksPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(self::FIXTURE);
    }

    public function testFixtureContainsOutlinesCatalogEntry(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/Outlines', $bytes);
        self::assertStringContainsString('/Type /Outlines', $bytes);
    }

    public function testFixtureContainsAnnotsArrayOnEachPageWithLinks(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertGreaterThanOrEqual(3, substr_count($bytes, '/Annots ['));
    }

    public function testFixtureContainsBothUriAndGoToActions(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/S /URI', $bytes);
        self::assertStringContainsString('/S /GoTo', $bytes);
    }

    public function testFixtureOutlineTreeHasExpectedDepth(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertSame(6, substr_count($bytes, '/Title ('));
    }

    public function buildDocument(): Document
    {
        $doc = new Document(Unit::PT);

        $page1 = $doc->addPage();
        $page1->setFont(Font::helvetica()->bold(), 18);
        $page1->text(50, 60, 'Chapter 1');
        $page1->setFont(Font::helvetica(), 11);
        $page1->text(50, 100, 'Visit https://example.com for the project home page.');
        $page1->link(50, 90, 200, 14, Link::url('https://example.com'));
        $page1->text(50, 140, 'Jump to Chapter 3.');
        $page1->link(50, 130, 200, 14, Link::destination(Destination::page(2)));

        $page2 = $doc->addPage();
        $page2->setFont(Font::helvetica()->bold(), 18);
        $page2->text(50, 60, 'Chapter 2');
        $page2->setFont(Font::helvetica(), 11);
        $page2->text(50, 100, 'See Wikipedia for the PDF spec.');
        $page2->link(50, 90, 200, 14, Link::url('https://en.wikipedia.org/wiki/PDF'));
        $page2->text(50, 140, 'Back to Chapter 1.');
        $page2->link(50, 130, 200, 14, Link::destination(Destination::page(0)));

        $page3 = $doc->addPage();
        $page3->setFont(Font::helvetica()->bold(), 18);
        $page3->text(50, 60, 'Chapter 3');
        $page3->setFont(Font::helvetica(), 11);
        $page3->text(50, 100, 'Email the maintainer.');
        $page3->link(50, 90, 200, 14, Link::url('mailto:test@example.com'));

        $root = $doc->outline();
        $chap1 = $root->add('Chapter 1', Destination::page(0));
        $chap1->add('Section 1.1', Destination::page(0));
        $chap1->add('Section 1.2', Destination::page(0));
        $chap2 = $root->add('Chapter 2', Destination::page(1));
        $chap2->add('Section 2.1', Destination::page(1));
        $root->add('Chapter 3', Destination::page(2));

        return $doc;
    }
}
