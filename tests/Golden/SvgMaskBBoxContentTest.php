<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SvgMaskBBoxContentTest extends TestCase
{
    public function testMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg-mask-bbox-content.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }
        $process = new Process([$qpdf, '--check', __DIR__ . '/fixtures/svg-mask-bbox-content.pdf']);
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
        // maskContentUnits=objectBoundingBox: child coords are unit fractions of the
        // masked element's bbox. A white circle at (0.5, 0.5) r=0.35 should reveal
        // a centered circular region of the rect regardless of the rect's size.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<mask id="m" maskUnits="objectBoundingBox" maskContentUnits="objectBoundingBox">'
            .   '<rect x="0" y="0" width="1" height="1" fill="black"/>'
            .   '<circle cx="0.5" cy="0.5" r="0.35" fill="white"/>'
            . '</mask>'
            . '</defs>'
            . '<rect x="10" y="10" width="80" height="80" fill="orange" mask="url(#m)"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 200.0);
        return $doc->output();
    }
}
