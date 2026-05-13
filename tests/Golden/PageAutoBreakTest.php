<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\NextPosition;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\PageMargins;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PageAutoBreakTest extends TestCase
{
    public function testPageAutoBreakMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/page-auto-break.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            $this->buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPageAutoBreakPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }

        $process = new Process([
            $qpdf,
            '--check',
            __DIR__ . '/fixtures/page-auto-break.pdf',
        ]);
        $process->run();
        self::assertSame(
            0,
            $process->getExitCode(),
            "qpdf --check failed:\nstdout:\n" . $process->getOutput() . "\nstderr:\n" . $process->getErrorOutput(),
        );
    }

    private function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->setMargins(new PageMargins(top: 60.0, right: 50.0, bottom: 60.0, left: 50.0));
        $doc->setHeader(function (Page $p): void {
            $p->setFont(Font::helvetica()->bold(), 11);
            $p->text(50, 35, 'Auto-break demo');
        });
        $doc->setFooter(function (Page $p, int $n, int $total): void {
            $p->setFont(Font::helvetica(), 9);
            $p->text(50, 800, "Page {$n} / {$total}");
        });
        $doc->setAutoPageBreak(true);

        $doc->addPage();
        $doc->getCurrentPage()->setFont(Font::helvetica(), 11);
        for ($i = 1; $i <= 60; $i++) {
            $doc->getCurrentPage()->cell(
                w: 495.0,
                h: 16.0,
                text: "Row {$i}",
                border: Border::all(),
                ln: NextPosition::NEWLINE,
            );
        }

        return $doc->output();
    }
}
