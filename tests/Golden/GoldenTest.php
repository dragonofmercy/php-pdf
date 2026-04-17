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

    public function testDocumentWithMetadataMatchesFixtureBytes(): void
    {
        $doc = new Document();
        $doc->metadata()
            ->title('Test')
            ->author('User')
            ->subject('Phase 1a')
            ->keywords('phppdf, test')
            ->creator('Test Suite')
            ->creationDate(new \DateTimeImmutable('2026-01-01T12:00:00+00:00'))
            ->documentId('abcdef0123456789abcdef0123456789');
        $doc->addPage();
        $actual = $doc->output();

        $expected = file_get_contents(__DIR__ . '/fixtures/document-with-metadata.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            $actual,
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testDocumentWithMetadataPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }

        $doc = new Document();
        $doc->metadata()
            ->title('Test')
            ->author('User')
            ->creationDate(new \DateTimeImmutable('2026-01-01T12:00:00+00:00'))
            ->documentId('abcdef0123456789abcdef0123456789');
        $doc->addPage();
        $tmp = tempnam(sys_get_temp_dir(), 'phppdf_golden_meta_');
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

    public function testEncryptedDocumentMatchesFixtureBytes(): void
    {
        $doc = new Document();
        $doc->metadata()
            ->title('Confidential')
            ->author('User')
            ->creationDate(new \DateTimeImmutable('2026-01-01T12:00:00+00:00'))
            ->documentId('abcdef0123456789abcdef0123456789');
        $doc->encryption()
            ->userPassword('user')
            ->ownerPassword('owner')
            ->allowPrint()
            ->allowCopy()
            ->withRandomSource(fn (int $n) => str_repeat("\x00", $n));
        $doc->addPage();
        $actual = $doc->output();

        $expected = file_get_contents(__DIR__ . '/fixtures/encrypted-document.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            $actual,
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testEncryptedDocumentDecryptsWithQpdf(): void
    {
        $qpdf = (new \Symfony\Component\Process\ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping decrypt round-trip.');
        }

        $decrypted = tempnam(sys_get_temp_dir(), 'phppdf_dec_');
        self::assertIsString($decrypted);

        try {
            $process = new \Symfony\Component\Process\Process([
                $qpdf,
                '--password=user',
                '--decrypt',
                __DIR__ . '/fixtures/encrypted-document.pdf',
                $decrypted,
            ]);
            $process->run();
            self::assertSame(
                0,
                $process->getExitCode(),
                "qpdf decrypt failed:\nstdout:\n" . $process->getOutput() . "\nstderr:\n" . $process->getErrorOutput(),
            );
            $content = file_get_contents($decrypted);
            self::assertIsString($content);
            self::assertStringStartsWith("%PDF-", $content);
            self::assertStringEndsWith("%%EOF\n", $content);
        } finally {
            @unlink($decrypted);
        }
    }
}
