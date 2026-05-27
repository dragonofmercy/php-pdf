<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SvgGradientHrefInheritTest extends TestCase
{
    public function testSvgGradientHrefInheritMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg-gradient-href-inherit.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgGradientHrefInheritPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }
        $process = new Process([$qpdf, '--check', __DIR__ . '/fixtures/svg-gradient-href-inherit.pdf']);
        $process->run();
        self::assertSame(
            0,
            $process->getExitCode(),
            "qpdf --check failed:\nstdout:\n" . $process->getOutput() . "\nstderr:\n" . $process->getErrorOutput(),
        );
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 100 100">'
            . '<defs>'
            . '<linearGradient id="base">'
            . '<stop offset="0" stop-color="#000000"/>'
            . '<stop offset="1" stop-color="#ffffff"/>'
            . '</linearGradient>'
            . '<linearGradient id="g" xlink:href="#base" x1="0" y1="0" x2="0" y2="1"/>'
            . '</defs>'
            . '<rect width="100" height="100" fill="url(#g)"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 200.0);
        return $doc->output();
    }
}
