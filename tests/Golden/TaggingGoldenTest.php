<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
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
}
