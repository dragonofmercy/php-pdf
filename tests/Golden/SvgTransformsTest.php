<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SvgTransformsTest extends TestCase
{
    public function testSvgTransformsMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg/basic/transforms.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgTransformsPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }
        $process = new Process([$qpdf, '--check', __DIR__ . '/fixtures/svg/basic/transforms.pdf']);
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
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">'
            . '<rect width="200" height="200" fill="lightyellow"/>'
            . '<g transform="translate(100, 100)">'
            . '<rect x="-10" y="-10" width="20" height="20" fill="navy"/>'
            . '</g>'
            . '<g transform="translate(50, 50) rotate(45)">'
            . '<rect x="-15" y="-15" width="30" height="30" fill="crimson"/>'
            . '</g>'
            . '<g transform="translate(150, 50) scale(2)">'
            . '<rect x="-8" y="-8" width="16" height="16" fill="green"/>'
            . '</g>'
            . '<g transform="translate(100, 150) rotate(45) scale(1.5)">'
            . '<rect width="20" height="20" fill="purple"/>'
            . '</g>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 300.0);
        return $doc->output();
    }
}
