<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SvgFillRuleEvenoddTest extends TestCase
{
    public function testSvgFillRuleEvenoddMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg-fill-rule-evenodd.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgFillRuleEvenoddPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }
        $process = new Process([$qpdf, '--check', __DIR__ . '/fixtures/svg-fill-rule-evenodd.pdf']);
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
        // Five-pointed star path: evenodd (hollow center) on the left, nonzero (filled center) on the right
        $starPath = 'M 50 10 L 61 35 L 90 35 L 67 55 L 76 80 L 50 62 L 24 80 L 33 55 L 10 35 L 39 35 Z';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 100">'
            . '<path d="' . $starPath . '" fill="gold" stroke="black" stroke-width="1" fill-rule="evenodd"/>'
            . '<g transform="translate(100, 0)">'
            . '<path d="' . $starPath . '" fill="gold" stroke="black" stroke-width="1" fill-rule="nonzero"/>'
            . '</g>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->currentPage()->image($img, x: 50.0, y: 50.0, w: 400.0);
        return $doc->output();
    }
}
