<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Text\Direction;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class RtlHebrewCellTest extends TestCase
{
    public function testMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/rtl/hebrew-cell.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }
        $process = new Process([$qpdf, '--check', __DIR__ . '/fixtures/rtl/hebrew-cell.pdf']);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $doc->registerFontFamily('FS', regular: __DIR__ . '/assets/fonts/FreeSans.ttf');
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 16);

        // Pure Hebrew (shalom), RTL.
        $page->cell(x: 20, y: 20, w: 120, h: 12, text: "\u{05E9}\u{05DC}\u{05D5}\u{05DD}", direction: Direction::RTL);
        // Mixed Hebrew + Latin + number, AUTO base (first strong char is Hebrew -> RTL base).
        $page->cell(x: 20, y: 35, w: 120, h: 12, text: "\u{05E9}\u{05DC}\u{05D5}\u{05DD} World 2026", direction: Direction::AUTO);

        return $doc->output();
    }
}
