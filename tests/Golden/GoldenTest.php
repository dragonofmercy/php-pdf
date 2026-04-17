<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Golden;

use PhpPdf\Document;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class GoldenTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/empty-page.pdf';

    public function testEmptyPageMatchesFixtureBytes(): void
    {
        $doc = new Document();
        $doc->addPage();
        $actual = $doc->output();

        $expected = file_get_contents(self::FIXTURE);
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            $actual,
            'Document output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testEmptyPagePassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }

        $doc = new Document();
        $doc->addPage();
        $tmp = tempnam(sys_get_temp_dir(), 'phppdf_golden_');
        self::assertIsString($tmp);

        try {
            file_put_contents($tmp, $doc->output());
            $process = new Process([$qpdf, '--check', $tmp]);
            $process->run();
            self::assertSame(
                0,
                $process->getExitCode(),
                "qpdf --check failed:\nstdout:\n" . $process->getOutput() . "\nstderr:\n" . $process->getErrorOutput(),
            );
        } finally {
            @unlink($tmp);
        }
    }
}
