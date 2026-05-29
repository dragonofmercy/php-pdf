<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PageWithOtfTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/page/otf.pdf';
    private const string FONTS_DIR = __DIR__ . '/fixtures/fonts';

    public function testPageWithOtfMatchesFixtureBytes(): void
    {
        if (!is_file(self::FONTS_DIR . '/IBMPlexSans-Regular.otf')) {
            self::markTestSkipped('IBM Plex Sans OTF fixture absent');
        }
        $expected = file_get_contents(self::FIXTURE);
        self::assertIsString($expected, 'Golden fixture missing - regenerate with tests/Golden/regenerate.php');
        self::assertSame(
            $expected,
            $this->buildDocument()->output(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPageWithOtfPassesQpdfCheck(): void
    {
        if (!is_file(self::FONTS_DIR . '/IBMPlexSans-Regular.otf')) {
            self::markTestSkipped('IBM Plex Sans OTF fixture absent');
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

    public function testFixtureEmbedsOpenTypeCidFontType0(): void
    {
        if (!is_file(self::FONTS_DIR . '/IBMPlexSans-Regular.otf')) {
            self::markTestSkipped('IBM Plex Sans OTF fixture absent');
        }
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/Subtype /CIDFontType0', $bytes);
        self::assertStringContainsString('/Subtype /OpenType', $bytes);
    }

    public function testFixtureBaseFontHasSubsetPrefix(): void
    {
        if (!is_file(self::FONTS_DIR . '/IBMPlexSans-Regular.otf')) {
            self::markTestSkipped('IBM Plex Sans OTF fixture absent');
        }
        $bytes = $this->buildDocument()->output();
        self::assertMatchesRegularExpression('#/BaseFont /[A-Z]{6}\+IBMPlexSans#', $bytes);
    }

    public function testFontFile3StreamIsMuchSmallerThanWholeOtf(): void
    {
        if (!is_file(self::FONTS_DIR . '/IBMPlexSans-Regular.otf')) {
            self::markTestSkipped('IBM Plex Sans OTF fixture absent');
        }
        $bytes = $this->buildDocument()->output();
        $regularSize = filesize(self::FONTS_DIR . '/IBMPlexSans-Regular.otf');
        $boldSize = filesize(self::FONTS_DIR . '/IBMPlexSans-Bold.otf');
        self::assertIsInt($regularSize);
        self::assertIsInt($boldSize);
        // The PDF embeds both Regular + Bold subsetted. With 30-60 latin glyphs in
        // each subset plus PDF structure the resulting bytes must be well under
        // the combined raw OTF size (sanity check that subsetting actually fires).
        self::assertLessThan((int) (($regularSize + $boldSize) * 0.5), strlen($bytes));
    }

    public function buildDocument(): Document
    {
        $doc = new Document(Unit::PT);
        $doc->registerFontFamily(
            'Plex',
            regular: self::FONTS_DIR . '/IBMPlexSans-Regular.otf',
            bold: self::FONTS_DIR . '/IBMPlexSans-Bold.otf',
        );

        $page = $doc->addPage();

        $page->setFont(Font::helvetica(), 11);
        $page->text(50, 50, 'Standard Helvetica baseline');

        $page->setFont(Font::custom('Plex'), 14);
        $page->text(50, 80, 'Custom IBM Plex Sans regular');

        $page->setFont(Font::custom('Plex')->bold(), 14);
        $page->text(50, 110, 'Custom IBM Plex Sans bold');

        $page->setFont(Font::custom('Plex'), 12);
        $page->text(50, 140, 'Resume cafe naivete oeuvre');

        return $doc;
    }
}
