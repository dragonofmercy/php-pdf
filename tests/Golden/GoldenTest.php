<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class GoldenTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/empty-page.pdf';

    public function testEmptyPageMatchesFixtureBytes(): void
    {
        $doc = new Document(Unit::PT);
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

        $doc = new Document(Unit::PT);
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
        $doc = new Document(Unit::PT);
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

        $doc = new Document(Unit::PT);
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
        $doc = new Document(Unit::PT);
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
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();

        $page->setStrokeColor(\DragonOfMercy\PhpPdf\Color::hex('#ff0000'))
             ->setLineWidth(1)
             ->rect(20, 20, 100, 50)
             ->stroke();

        $page->setFillColor(\DragonOfMercy\PhpPdf\Color::rgb(0, 0, 255))
             ->circle(200, 200, 40)
             ->fill();

        $page->setStrokeColor(\DragonOfMercy\PhpPdf\Color::gray(128))
             ->setLineWidth(2)
             ->line(0, 0, 595, 842)
             ->stroke();

        $page->setFillColor(\DragonOfMercy\PhpPdf\Color::hex('#00aa00'))
             ->path()
             ->moveTo(300, 500)
             ->lineTo(400, 500)
             ->lineTo(350, 450)
             ->close()
             ->fill();

        $page->save()
             ->translate(450, 100)
             ->rotate(30)
             ->setFillColor(\DragonOfMercy\PhpPdf\Color::hex('#ff8800'))
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
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();

        $page->setFont(\DragonOfMercy\PhpPdf\Font::helvetica()->bold(), 18);
        $page->text(50, 50, 'Hello World');

        $page->setFont(\DragonOfMercy\PhpPdf\Font::times()->italic(), 12);
        $page->text(50, 100, 'Résumé — café, naïveté, œuvre');

        $page->setFont(\DragonOfMercy\PhpPdf\Font::courier(), 10);
        $page->text(50, 150, "Line 1\nLine 2\nLine 3");

        $page->setFont(\DragonOfMercy\PhpPdf\Font::helvetica(), 12);
        $page->text(50, 190, 'Smørrebrød, skål, äpplen, Þórsdagur');

        $page->setFont(\DragonOfMercy\PhpPdf\Font::helvetica(), 14);
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

    public function testPageWithCellsMatchesFixtureBytes(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(\DragonOfMercy\PhpPdf\Font::helvetica(), 12);

        $page->cell(
            x: 50, y: 50, w: 300, h: 25,
            text: 'Invoice #2026-001',
            border: \DragonOfMercy\PhpPdf\Border::all()->withWidth(0.8),
            fill: \DragonOfMercy\PhpPdf\Color::rgb(242, 242, 242),
            align: \DragonOfMercy\PhpPdf\TextAlign::CENTER,
            verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::MIDDLE,
        );

        $result = $page->cell(
            x: 50, y: 80, w: 300,
            text: 'Long paragraph that wraps automatically across multiple lines depending on the available width.',
            border: \DragonOfMercy\PhpPdf\Border::all()->withStyle(\DragonOfMercy\PhpPdf\BorderStyle::DASHED),
        );

        $page->cell(
            x: 50, y: $result->y + 5, w: 300, h: 20,
            text: 'Total: 1234.56 EUR',
            textColor: \DragonOfMercy\PhpPdf\Color::rgb(192, 0, 0),
            align: \DragonOfMercy\PhpPdf\TextAlign::RIGHT,
            verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::MIDDLE,
        );

        $page->cell(
            x: 50, y: 200, w: 100, h: 20,
            text: 'Antidisestablishmentarianism',
            border: \DragonOfMercy\PhpPdf\Border::all(),
            fit: \DragonOfMercy\PhpPdf\Fit::CONDENSE,
            verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::MIDDLE,
        );

        $page->cell(
            x: 200, y: 200, w: 100, h: 20,
            text: 'Antidisestablishmentarianism',
            border: \DragonOfMercy\PhpPdf\Border::all(),
            fit: \DragonOfMercy\PhpPdf\Fit::SHRINK,
            verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::MIDDLE,
        );

        $page->cell(
            x: 50, y: 240, w: 300, h: 18,
            text: 'Top-and-bottom only',
            border: \DragonOfMercy\PhpPdf\Border::sides(top: true, bottom: true)->withStyle(\DragonOfMercy\PhpPdf\BorderStyle::DASHED),
            align: \DragonOfMercy\PhpPdf\TextAlign::CENTER,
            verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::MIDDLE,
        );

        $page->cell(
            x: 50, y: 270, w: 300, h: 18,
            text: 'Dotted',
            border: \DragonOfMercy\PhpPdf\Border::all()->withStyle(\DragonOfMercy\PhpPdf\BorderStyle::DOTTED)->withWidth(1.0),
            align: \DragonOfMercy\PhpPdf\TextAlign::CENTER,
            verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::MIDDLE,
        );

        $page->cell(
            x: 50, y: 300, w: 300, h: 8,
            text: '',
            border: \DragonOfMercy\PhpPdf\Border::all(),
            fill: \DragonOfMercy\PhpPdf\Color::rgb(220, 220, 220),
        );

        $actual = $doc->output();
        $expected = file_get_contents(__DIR__ . '/fixtures/page-with-cells.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            $actual,
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPageWithCellsPassesQpdfCheck(): void
    {
        $qpdf = (new \Symfony\Component\Process\ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }

        $process = new \Symfony\Component\Process\Process([
            $qpdf,
            '--check',
            __DIR__ . '/fixtures/page-with-cells.pdf',
        ]);
        $process->run();
        self::assertSame(
            0,
            $process->getExitCode(),
            "qpdf --check failed:\nstdout:\n" . $process->getOutput() . "\nstderr:\n" . $process->getErrorOutput(),
        );
    }
}
