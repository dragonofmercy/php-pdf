<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\PageMargins;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class MarkdownFlowTest extends TestCase
{
    public function testMarkdownFlowMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/markdown-flow.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testMarkdownFlowPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }

        $process = new Process([
            $qpdf,
            '--check',
            __DIR__ . '/fixtures/markdown-flow.pdf',
        ]);
        $process->run();
        self::assertSame(
            0,
            $process->getExitCode(),
            "qpdf --check failed:\nstdout:\n" . $process->getOutput() . "\nstderr:\n" . $process->getErrorOutput(),
        );
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $doc->setMargins(PageMargins::all(20));
        $doc->setAutoPageBreak(false);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11);
        $page->markdown(MarkdownSample::TEXT, x: 20, y: 20);

        return $doc->output();
    }
}
