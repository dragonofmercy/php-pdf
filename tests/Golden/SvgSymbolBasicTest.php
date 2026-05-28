<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SvgSymbolBasicTest extends TestCase
{
    public function testSvgSymbolBasicMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg-symbol-basic.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgSymbolBasicPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }
        $process = new Process([$qpdf, '--check', __DIR__ . '/fixtures/svg-symbol-basic.pdf']);
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
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 60">'
            . '<defs><symbol id="star" viewBox="0 0 10 10"><polygon points="5,0 6,4 10,4 7,6 8,10 5,8 2,10 3,6 0,4 4,4" fill="#cc6600"/></symbol></defs>'
            . '<use href="#star" x="5" y="5" width="20" height="20"/>'
            . '<use href="#star" x="40" y="5" width="30" height="30"/>'
            . '<use href="#star" x="80" y="5" width="15" height="15"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 240.0);
        return $doc->output();
    }
}
