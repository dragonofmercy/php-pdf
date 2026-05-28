<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SvgMarkerLineTest extends TestCase
{
    public function testSvgMarkerLineMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg-marker-line.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgMarkerLinePassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }
        $process = new Process([$qpdf, '--check', __DIR__ . '/fixtures/svg-marker-line.pdf']);
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
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 30">'
            . '<defs>'
            . '<marker id="arrow" viewBox="0 0 10 10" refX="10" refY="5" markerWidth="6" markerHeight="6" orient="auto">'
            . '<path d="M0 0 L10 5 L0 10 Z" fill="#000"/>'
            . '</marker>'
            . '</defs>'
            . '<line x1="10" y1="15" x2="90" y2="15" stroke="#000" stroke-width="2" marker-end="url(#arrow)"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 240.0);
        return $doc->output();
    }
}
