<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SvgTextCustomAnchorStrokeTest extends TestCase
{
    private const string FIXTURE = 'svg/text/custom-anchor-stroke.pdf';
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

        $tmp = tempnam(sys_get_temp_dir(), 'phppdf-svg-anchor-') . '.pdf';
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
            . '<text x="100" y="60" text-anchor="middle" font-family="FS" font-size="28" fill="#1040a0"'
            . ' fill-opacity="0.7" stroke="#000000" stroke-width="0.6" transform="rotate(-8 100 60)">Anchored</text>'
            . '</svg>';
        $doc = new Document(Unit::MM);
        $doc->registerFontFamily('FS', regular: self::FONTS . '/FreeSans.ttf', bold: self::FONTS . '/FreeSansBold.ttf');
        $page = $doc->addPage();
        $page->image(Image::fromBytes($svg), x: 20.0, y: 20.0, w: 100.0);
        return $doc->output();
    }
}
