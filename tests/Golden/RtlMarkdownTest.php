<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\NextPosition;
use DragonOfMercy\PhpPdf\Text\Direction;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class RtlMarkdownTest extends TestCase
{
    public function testMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/markdown/rtl-blocks.pdf');
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
        $process = new Process([$qpdf, '--check', __DIR__ . '/fixtures/markdown/rtl-blocks.pdf']);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $doc->registerFontFamily('FS', regular: __DIR__ . '/assets/fonts/FreeSerif.ttf');
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 14);

        // Hebrew "shalom olam", Arabic "namir bila", Hebrew heading, mixed
        // Arabic + Latin number, and a Hebrew line with inline code in the
        // middle. The blank lines split them into separate Markdown blocks.
        $hebrewParagraph = "\u{05E9}\u{05DC}\u{05D5}\u{05DD} \u{05E2}\u{05D5}\u{05DC}\u{05DD}";
        $arabicParagraph = "\u{0646}\u{0645}\u{0631} \u{0628}\u{0644}\u{0627}";
        $heading = "# " . "\u{05E9}\u{05DC}\u{05D5}\u{05DD}";
        $mixed = "\u{0646}\u{0645}\u{0631} 2026";
        $inlineCode = "\u{05E9}\u{05DC}\u{05D5}\u{05DD} `code` \u{05E2}\u{05D5}\u{05DC}\u{05DD}";

        $md = implode("\n\n", [
            $hebrewParagraph,
            $arabicParagraph,
            $heading,
            $mixed,
            $inlineCode,
        ]);

        $page->markdown($md, 20.0, 20.0, 170.0, null, NextPosition::BELOW, Direction::RTL);

        return $doc->output();
    }
}
