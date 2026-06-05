<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
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
}
