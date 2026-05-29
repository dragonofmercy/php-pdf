<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SvgTextCustomTtfTest extends TestCase
{
    private const string FIXTURE = 'svg/text/custom-ttf.pdf';
    private const string FONTS = __DIR__ . '/fixtures/fonts';

    public static function fontsPresent(): bool
    {
        return is_file(self::FONTS . '/FreeSans.ttf') && is_file(self::FONTS . '/FreeSansBold.ttf');
    }

    public function testMatchesFixtureBytes(): void
    {
        if (!self::fontsPresent()) {
            self::markTestSkipped('FreeSans fixtures absent');
        }

        $expected = file_get_contents(__DIR__ . '/fixtures/' . self::FIXTURE);
        self::assertIsString($expected, 'Golden fixture missing - regenerate with tests/Golden/regenerate.php');
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPassesQpdfCheck(): void
    {
        if (!self::fontsPresent()) {
            self::markTestSkipped('FreeSans fixtures absent');
        }

        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'phppdf-svg-ttf-') . '.pdf';
        file_put_contents($tmp, self::buildPdfBytes());
        try {
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

    public static function buildPdfBytes(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 120">'
            . '<text x="10" y="40" font-family="FS" font-size="24" fill="#102030">Cafe creme</text>'
            . '<text x="10" y="80" font-family="FS" font-weight="bold" font-size="24" fill="#902020">Gras</text>'
            . '</svg>';
        $doc = new Document(Unit::MM);
        $doc->registerFontFamily('FS', regular: self::FONTS . '/FreeSans.ttf', bold: self::FONTS . '/FreeSansBold.ttf');
        $page = $doc->addPage();
        $page->image(Image::fromBytes($svg), x: 20.0, y: 20.0, w: 100.0);
        return $doc->output();
    }
}
