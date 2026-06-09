<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Table\Cell;
use DragonOfMercy\PhpPdf\Table\Column;
use DragonOfMercy\PhpPdf\Text\Direction;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class RtlArabicTableTest extends TestCase
{
    public function testMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/rtl/arabic-table.pdf');
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
        $process = new Process([$qpdf, '--check', __DIR__ . '/fixtures/rtl/arabic-table.pdf']);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $doc->registerFontFamily('FS', regular: __DIR__ . '/assets/fonts/FreeSerif.ttf');
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 12);

        $columns = [
            Column::of('word', 'Word')->fill(),
            Column::of('phrase', 'Phrase')->width(70.0),
        ];
        // Row 1: noon+meem+reh ("tiger") / beh+lam+alef ("bla"), both RTL.
        // Row 2: seen+lam+alef+meem ("peace") / beh+lam+alef + space + noon+meem+reh, both RTL.
        $rows = [
            [
                'word'   => Cell::of("\u{0646}\u{0645}\u{0631}")->direction(Direction::RTL),
                'phrase' => Cell::of("\u{0628}\u{0644}\u{0627}")->direction(Direction::RTL),
            ],
            [
                'word'   => Cell::of("\u{0633}\u{0644}\u{0627}\u{0645}")->direction(Direction::RTL),
                'phrase' => Cell::of("\u{0628}\u{0644}\u{0627} \u{0646}\u{0645}\u{0631}")->direction(Direction::RTL),
            ],
        ];
        $page->table($columns, $rows, x: 20, y: 20, width: 120);

        return $doc->output();
    }
}
