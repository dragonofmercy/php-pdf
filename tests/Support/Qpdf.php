<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Support;

use PHPUnit\Framework\Assert;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Structural validation of a generated PDF through the qpdf CLI. Every golden
 * test pairs its byte-identity assertion with one of these; the check is
 * skipped (not failed) on machines where qpdf is not installed.
 */
final class Qpdf
{
    /**
     * Runs `qpdf --check` on the file and asserts a clean exit, reporting
     * qpdf's own output on failure. Skips the calling test when qpdf is not
     * on PATH.
     */
    public static function assertCheck(string $path): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            Assert::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }

        $process = new Process([$qpdf, '--check', $path]);
        $process->run();

        Assert::assertSame(0, $process->getExitCode(), 'qpdf --check failed: ' . $process->getOutput() . $process->getErrorOutput());
    }
}
