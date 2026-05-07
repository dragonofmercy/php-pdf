<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Common machinery for the per-format barcode golden tests:
 * - byte-by-byte fixture comparison
 * - qpdf --check structural validation (skipped when qpdf is absent)
 *
 * Each subclass implements {@see fixturePath()} and {@see buildPdfBytes()}.
 */
abstract class AbstractBarcodeGoldenTest extends TestCase
{
    abstract protected function fixturePath(): string;

    abstract protected function buildPdfBytes(): string;

    public function testMatchesFixtureBytes(): void
    {
        $expected = file_get_contents($this->fixturePath());
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            $this->buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }
        $process = new Process([$qpdf, '--check', $this->fixturePath()]);
        $process->run();
        self::assertSame(
            0,
            $process->getExitCode(),
            "qpdf --check failed:\nstdout:\n" . $process->getOutput() . "\nstderr:\n" . $process->getErrorOutput(),
        );
    }
}
