<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\PageMargins;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PageWithHeaderFooterTest extends TestCase
{
    public function testPageWithHeaderFooterMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/page/header-footer.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            $this->buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPageWithHeaderFooterPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }

        $process = new Process([
            $qpdf,
            '--check',
            __DIR__ . '/fixtures/page/header-footer.pdf',
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
        $doc->setMargins(new PageMargins(top: 80.0, right: 50.0, bottom: 60.0, left: 50.0));
        $doc->setHeader(function (Page $p): void {
            $p->setFont(Font::helvetica()->bold(), 14);
            $p->text(50, 40, 'Phase 6 Sample');
            $p->setLineWidth(0.5);
            $p->line(50, 65, 545, 65)->stroke();
        });
        $doc->setFooter(function (Page $p, int $n, int $total): void {
            $p->setFont(Font::helvetica(), 9);
            $p->text(50, 800, "Page {$n} / {$total}");
        });

        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11);
        $page->text(50, 100, 'Body content positioned below the header zone.');
        $page->text(50, 120, 'Page numbering appears in the footer band.');

        return $doc->output();
    }
}
