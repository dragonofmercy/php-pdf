<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PageWithImagesTest extends TestCase
{
    public function testPageWithImagesMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/page/images.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            $this->buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPageWithImagesPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }

        $process = new Process([
            $qpdf,
            '--check',
            __DIR__ . '/fixtures/page/images.pdf',
        ]);
        $process->run();
        self::assertSame(
            0,
            $process->getExitCode(),
            "qpdf --check failed:\nstdout:\n" . $process->getOutput() . "\nstderr:\n" . $process->getErrorOutput(),
        );
    }

    private function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();

        $assets = __DIR__ . '/assets';
        $jpegImage = Image::fromFile($assets . '/jpeg-rgb-32x16.jpg');
        $pngOpaqueImage = Image::fromFile($assets . '/png-opaque-rgb-24x12.png');
        $pngAlphaImage = Image::fromFile($assets . '/png-alpha-rgba-16x16.png');
        $paletteImage = Image::fromBytes(TestImageFactory::pngPalette(width: 8, height: 8));

        $page->image($jpegImage, x: 50, y: 50, w: 200, h: 100);
        $page->image($pngOpaqueImage, x: 50, y: 200, w: 150);
        $page->image($pngAlphaImage, x: 250, y: 200, w: 100, h: 100);
        $page->image($paletteImage, x: 400, y: 200);

        return $doc->output();
    }
}
