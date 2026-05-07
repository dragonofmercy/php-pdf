<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Barcode\Ean13;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class BarcodeEan13Test extends TestCase
{
    public function testBarcodeEan13MatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/barcode-ean13.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            $this->buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testBarcodeEan13PassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }

        $process = new Process([
            $qpdf,
            '--check',
            __DIR__ . '/fixtures/barcode-ean13.pdf',
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
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $page->barcode(
            Ean13::of('9780131103627'),
            x: 20.0, y: 20.0, w: 50.0, h: 18.0,
        );
        return $doc->output();
    }
}
