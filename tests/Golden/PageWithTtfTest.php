<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PageWithTtfTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/page/ttf.pdf';
    private const string FONTS_DIR = __DIR__ . '/fixtures/fonts';

    public function testPageWithTtfMatchesFixtureBytes(): void
    {
        if (!is_file(self::FONTS_DIR . '/FreeSans.ttf')) {
            self::markTestSkipped('FreeSans fixtures absent');
        }

        $expected = file_get_contents(self::FIXTURE);
        self::assertIsString($expected, 'Golden fixture missing - regenerate with tests/Golden/regenerate.php');
        self::assertSame(
            $expected,
            $this->buildDocument()->output(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testFixtureIsSubsettedNotWholeFont(): void
    {
        if (!is_file(self::FONTS_DIR . '/FreeSans.ttf')) {
            self::markTestSkipped('FreeSans fixtures absent');
        }
        $size = filesize(self::FIXTURE);
        self::assertIsInt($size);
        self::assertLessThan(
            200_000,
            $size,
            'page/ttf.pdf is not subsetted - did regenerate.php run after the subsetting change?',
        );
    }

    public function testPageWithTtfPassesQpdfCheck(): void
    {
        if (!is_file(self::FONTS_DIR . '/FreeSans.ttf')) {
            self::markTestSkipped('FreeSans fixtures absent');
        }

        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }

        $process = new Process([$qpdf, '--check', self::FIXTURE]);
        $process->run();
        self::assertSame(
            0,
            $process->getExitCode(),
            "qpdf --check failed:\nstdout:\n" . $process->getOutput() . "\nstderr:\n" . $process->getErrorOutput(),
        );
    }

    public function buildDocument(): Document
    {
        $doc = new Document(Unit::PT);
        $doc->registerFontFamily(
            'FS',
            regular: self::FONTS_DIR . '/FreeSans.ttf',
            bold: self::FONTS_DIR . '/FreeSansBold.ttf',
        );

        $page = $doc->addPage();

        $page->setFont(Font::helvetica(), 11);
        $page->text(50, 50, 'Standard Helvetica baseline');

        $page->setFont(Font::custom('FS'), 14);
        $page->text(50, 80, 'Custom FreeSans regular');

        $page->setFont(Font::custom('FS')->bold(), 14);
        $page->text(50, 110, 'Custom FreeSans bold');

        $page->setFont(Font::custom('FS'), 12);
        $page->text(50, 140, 'Résumé café naïveté œuvre');

        $page->setFont(Font::custom('FS'), 12);
        $page->text(50, 170, 'α β γ δ ε ζ η θ');

        $page->setFont(Font::custom('FS')->bold(), 12);
        $page->text(50, 200, 'Москва Санкт-Петербург');

        return $doc;
    }
}
