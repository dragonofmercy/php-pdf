<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SvgClipGroupTest extends TestCase
{
    private const string FIXTURE = 'svg-clip-group.pdf';

    public function testSvgClipGroupMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/' . self::FIXTURE);
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgClipGroupPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }
        $process = new Process([$qpdf, '--check', __DIR__ . '/fixtures/' . self::FIXTURE]);
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
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<clipPath id="c"><path d="M50 5 L95 95 L5 95 Z"/></clipPath>'
            . '<g clip-path="url(#c)">'
            . '<rect x="0" y="0" width="50" height="100" fill="#2277dd"/>'
            . '<rect x="50" y="0" width="50" height="100" fill="#22aa44"/>'
            . '</g>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 40.0, y: 40.0, w: 300.0);
        return $doc->output();
    }
}
