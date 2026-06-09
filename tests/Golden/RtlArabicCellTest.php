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

final class RtlArabicCellTest extends TestCase
{
    public function testMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/rtl/arabic-cell.pdf');
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
        $process = new Process([$qpdf, '--check', __DIR__ . '/fixtures/rtl/arabic-cell.pdf']);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $doc->registerFontFamily('FS', regular: __DIR__ . '/assets/fonts/FreeSerif.ttf');
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 16);

        // Pure dual-joining Arabic word: noon+meem+reh = "tiger" (nimr), RTL.
        $page->cell(x: 20, y: 20, w: 120, h: 12, text: "\u{0646}\u{0645}\u{0631}", direction: Direction::RTL);
        // Phrase with lam-alef ligature: beh+lam+alef + space + noon+meem+reh, RTL.
        $page->cell(x: 20, y: 35, w: 120, h: 12, text: "\u{0628}\u{0644}\u{0627} \u{0646}\u{0645}\u{0631}", direction: Direction::RTL);
        // Mixed Arabic + Latin + number, AUTO base (first strong char is Arabic -> RTL base).
        $page->cell(x: 20, y: 50, w: 120, h: 12, text: "\u{0646}\u{0645}\u{0631} 2026", direction: Direction::AUTO);

        return $doc->output();
    }
}
