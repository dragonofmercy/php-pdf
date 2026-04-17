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

    public function testPageWithGraphicsMatchesFixtureBytes(): void
    {
        $doc = new Document();
        $page = $doc->addPage();

        $page->setStrokeColor(\PhpPdf\Color::hex('#ff0000'))
             ->setLineWidth(1)
             ->rect(20, 20, 100, 50)
             ->stroke();

        $page->setFillColor(\PhpPdf\Color::rgb(0, 0, 255))
             ->circle(200, 200, 40)
             ->fill();

        $page->setStrokeColor(\PhpPdf\Color::gray(128))
             ->setLineWidth(2)
             ->line(0, 0, 595, 842)
             ->stroke();

        $page->setFillColor(\PhpPdf\Color::hex('#00aa00'))
             ->path()
             ->moveTo(300, 500)
             ->lineTo(400, 500)
             ->lineTo(350, 450)
             ->close()
             ->fill();

        $page->save()
             ->translate(450, 100)
             ->rotate(30)
             ->setFillColor(\PhpPdf\Color::hex('#ff8800'))
             ->rect(-10, -10, 20, 20)
             ->fill();
        $page->restore();

        $actual = $doc->output();
        $expected = file_get_contents(__DIR__ . '/fixtures/page-with-graphics.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            $actual,
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPageWithGraphicsPassesQpdfCheck(): void
    {
        $qpdf = (new \Symfony\Component\Process\ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }

        $process = new \Symfony\Component\Process\Process([
            $qpdf,
            '--check',
            __DIR__ . '/fixtures/page-with-graphics.pdf',
        ]);
        $process->run();
        self::assertSame(
            0,
            $process->getExitCode(),
            "qpdf --check failed:\nstdout:\n" . $process->getOutput() . "\nstderr:\n" . $process->getErrorOutput(),
        );
    }

    public function testPageWithTextMatchesFixtureBytes(): void
    {
        $doc = new Document();
        $page = $doc->addPage();

        $page->setFont(\PhpPdf\Font::helvetica()->bold(), 18);
        $page->text(50, 50, 'Hello World');

        $page->setFont(\PhpPdf\Font::times()->italic(), 12);
        $page->text(50, 100, 'Résumé — café, naïveté, œuvre');

        $page->setFont(\PhpPdf\Font::courier(), 10);
        $page->text(50, 150, "Line 1\nLine 2\nLine 3");

        $page->setFont(\PhpPdf\Font::helvetica(), 14);
        $page->text(50, 220, 'Prix : 19,99 €');

        $actual = $doc->output();
        $expected = file_get_contents(__DIR__ . '/fixtures/page-with-text.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            $actual,
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPageWithTextPassesQpdfCheck(): void
    {
        $qpdf = (new \Symfony\Component\Process\ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }

        $process = new \Symfony\Component\Process\Process([
            $qpdf,
            '--check',
            __DIR__ . '/fixtures/page-with-text.pdf',
        ]);
        $process->run();
        self::assertSame(
            0,
            $process->getExitCode(),
            "qpdf --check failed:\nstdout:\n" . $process->getOutput() . "\nstderr:\n" . $process->getErrorOutput(),
        );
    }
}
