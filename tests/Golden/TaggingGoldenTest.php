<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\NextPosition;
use DragonOfMercy\PhpPdf\Outline\Destination;
use DragonOfMercy\PhpPdf\Outline\Link;
use DragonOfMercy\PhpPdf\Table\Column;
use DragonOfMercy\PhpPdf\Table\TableBorders;
use DragonOfMercy\PhpPdf\Table\TableStyle;
use DragonOfMercy\PhpPdf\TextAlign;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class TaggingGoldenTest extends TestCase
{
    public static function buildCellParagraph(): string
    {
        $doc = new Document();
        $doc->enableTagging('en-US');
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->cell(w: 80, h: 10, text: 'A tagged paragraph.');
        return $doc->output();
    }

    public function testCellParagraphMatchesFixture(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/tagging/cell-paragraph.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildCellParagraph(), 'tagging/cell-paragraph.pdf diverges; regenerate if intended.');
    }

    public function testCellParagraphPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf not on PATH');
        }
        $p = new Process([$qpdf, '--check', __DIR__ . '/fixtures/tagging/cell-paragraph.pdf']);
        $p->run();
        self::assertSame(0, $p->getExitCode(), (string) $p->getOutput());
    }

    public static function buildFigure(): string
    {
        $doc = new Document();
        $doc->enableTagging();
        $page = $doc->addPage();
        $page->image(__DIR__ . '/assets/png-opaque-rgb-24x12.png', x: 10, y: 10, w: 30, h: 30);
        return $doc->output();
    }

    public function testFigureMatchesFixture(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/tagging/figure.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildFigure(), 'tagging/figure.pdf diverges; regenerate if intended.');
    }

    public function testFigurePassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf not on PATH');
        }
        $p = new Process([$qpdf, '--check', __DIR__ . '/fixtures/tagging/figure.pdf']);
        $p->run();
        self::assertSame(0, $p->getExitCode(), (string) $p->getOutput());
    }

    public static function buildTable(): string
    {
        $doc = new Document();
        $doc->enableTagging('en-US');
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);
        $page->table(
            columns: [
                Column::of('name', 'Article')->fill(),
                Column::of('price', 'Prix')->width(30.0)->align(TextAlign::RIGHT),
            ],
            rows: [
                ['name' => 'Cafe', 'price' => '5.00'],
                ['name' => 'Croissant', 'price' => '3.60'],
            ],
            x: 20.0, y: 30.0, width: 170.0,
            style: TableStyle::default()
                ->withBorder(TableBorders::GRID)
                ->withHeader(fill: Color::gray(238), bold: true),
        );
        return $doc->output();
    }

    public function testTableMatchesFixture(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/tagging/table.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildTable(), 'tagging/table.pdf diverges; regenerate if intended.');
    }

    public function testTablePassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf not on PATH');
        }
        $p = new Process([$qpdf, '--check', __DIR__ . '/fixtures/tagging/table.pdf']);
        $p->run();
        self::assertSame(0, $p->getExitCode(), (string) $p->getOutput());
    }

    public static function buildMarkdown(): string
    {
        $doc = new Document();
        $doc->enableTagging('en-US');
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->markdown("# Title\n\nA paragraph.\n\n- one\n- two");
        return $doc->output();
    }

    public function testMarkdownMatchesFixture(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/tagging/markdown.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildMarkdown(), 'tagging/markdown.pdf diverges; regenerate if intended.');
    }

    public function testMarkdownPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf not on PATH');
        }
        $p = new Process([$qpdf, '--check', __DIR__ . '/fixtures/tagging/markdown.pdf']);
        $p->run();
        self::assertSame(0, $p->getExitCode(), (string) $p->getOutput());
    }

    public static function buildMarkdownLinks(): string
    {
        $doc = new Document();
        $doc->enableTagging('en-US');
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->markdown("A paragraph with an [inline link](https://example.com) in the middle.\n\n# A [heading link](https://example.org)");

        return $doc->output();
    }

    public function testMarkdownLinksMatchesFixture(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/tagging/markdown-links.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildMarkdownLinks(), 'tagging/markdown-links.pdf diverges; regenerate if intended.');
    }

    public function testMarkdownLinksPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf not on PATH');
        }
        $p = new Process([$qpdf, '--check', __DIR__ . '/fixtures/tagging/markdown-links.pdf']);
        $p->run();
        self::assertSame(0, $p->getExitCode(), (string) $p->getOutput());
    }

    private const string FONTS_DIR = __DIR__ . '/assets/fonts';

    /**
     * The shared deterministic PDF/UA-1 setup behind buildUaDocument() and
     * buildUaLinksDocument(): fixed title, embedded font, frozen creationDate /
     * documentId, and enablePdfUA. Returns a page-less document; each builder
     * adds its own pages and content. Factored out verbatim so the goldens stay
     * byte-identical.
     */
    private static function deterministicUaDoc(string $title): Document
    {
        $doc = new Document();
        $doc->metadata()
            ->title($title)
            ->creationDate(new \DateTimeImmutable('2026-01-01T12:00:00+00:00'))
            ->documentId('abcdef0123456789abcdef0123456789');
        $doc->registerFontFamily('Body', regular: self::FONTS_DIR . '/FreeSans.ttf', bold: self::FONTS_DIR . '/FreeSansBold.ttf');
        $doc->enablePdfUA('en-US');

        return $doc;
    }

    /**
     * Builds a full PDF/UA-1 document that exercises every clause fixed in
     * Phase 2: title, embedded font, a markdown heading + paragraph, a table
     * with a header row (TH /Scope /Column), and a figure with /Alt. Shared
     * with the veraPDF conformance gate (VeraPdfUa1Test) so both validate the
     * exact same bytes.
     */
    public static function buildUaDocument(): Document
    {
        $doc = self::deterministicUaDoc('Accessible report');

        $page = $doc->addPage();
        $page->setFont(Font::custom('Body'), 12.0);
        $page->markdown("# Accessible heading\n\nA paragraph of accessible body text.");
        $page->table(
            columns: [
                Column::of('name', 'Article')->fill(),
                Column::of('price', 'Prix')->width(30.0)->align(TextAlign::RIGHT),
            ],
            rows: [
                ['name' => 'Cafe', 'price' => '5.00'],
                ['name' => 'Croissant', 'price' => '3.60'],
            ],
            x: 20.0, y: 80.0, width: 170.0,
            style: TableStyle::default()
                ->withBorder(TableBorders::GRID)
                ->withHeader(fill: Color::gray(238), bold: true),
        );
        $page->image(__DIR__ . '/assets/png-opaque-rgb-24x12.png', x: 20.0, y: 150.0, w: 30.0, h: 30.0, alt: 'Company logo');

        return $doc;
    }

    public function testUaDocumentMatchesFixture(): void
    {
        if (!is_file(self::FONTS_DIR . '/FreeSans.ttf')) {
            self::markTestSkipped('FreeSans fixtures absent');
        }
        $expected = file_get_contents(__DIR__ . '/fixtures/tagging/ua-document.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildUaDocument()->output(), 'tagging/ua-document.pdf diverges; regenerate if intended.');
    }

    public function testUaDocumentPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf not on PATH');
        }
        $p = new Process([$qpdf, '--check', __DIR__ . '/fixtures/tagging/ua-document.pdf']);
        $p->run();
        self::assertSame(0, $p->getExitCode(), (string) $p->getOutput());
    }

    /**
     * A PDF/UA-1 document with tagged text hyperlinks: an external URL link
     * (carrying /Alt via linkAlt) and an internal go-to-page-1 link. Reuses the
     * same deterministic setup as buildUaDocument() so both the golden compare
     * and the veraPDF gate (VeraPdfUa1Test) validate identical bytes.
     */
    public static function buildUaLinksDocument(): Document
    {
        $doc = self::deterministicUaDoc('Accessible links');

        $page = $doc->addPage();
        $page->setFont(Font::custom('Body'), 12.0);
        $page->cell(w: 80.0, h: 8.0, text: 'Visit example.com', link: Link::url('https://example.com'), linkAlt: 'Example home page', ln: NextPosition::NEWLINE);
        $page->cell(w: 80.0, h: 8.0, text: 'Jump to page 1', link: Link::destination(Destination::page(0)));

        return $doc;
    }

    public function testUaLinksDocumentMatchesFixture(): void
    {
        if (!is_file(self::FONTS_DIR . '/FreeSans.ttf')) {
            self::markTestSkipped('FreeSans fixtures absent');
        }
        $expected = file_get_contents(__DIR__ . '/fixtures/tagging/ua-links.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildUaLinksDocument()->output(), 'tagging/ua-links.pdf diverges; regenerate if intended.');
    }

    public function testUaLinksDocumentPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf not on PATH');
        }
        $p = new Process([$qpdf, '--check', __DIR__ . '/fixtures/tagging/ua-links.pdf']);
        $p->run();
        self::assertSame(0, $p->getExitCode(), (string) $p->getOutput());
    }
}
