<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SvgFilterBlendMultiplyTest extends TestCase
{
    public function testSvgFilterBlendMultiplyMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg/filter/blend-multiply.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgFilterBlendMultiplyPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }
        $process = new Process([$qpdf, '--check', __DIR__ . '/fixtures/svg/filter/blend-multiply.pdf']);
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
            . '<defs>'
            . '<filter id="f" x="-10%" y="-10%" width="120%" height="120%">'
            .   '<feFlood flood-color="#ffcc00" result="flood"/>'
            .   '<feBlend in="SourceGraphic" in2="flood" mode="multiply"/>'
            . '</filter>'
            . '</defs>'
            . '<rect x="20" y="20" width="60" height="60" fill="#2299dd" filter="url(#f)"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 200.0);
        return $doc->output();
    }
}
