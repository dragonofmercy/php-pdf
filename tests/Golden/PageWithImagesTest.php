<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PageWithImagesTest extends TestCase
{
    public function testPageWithImagesMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/page-with-images.pdf');
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
            __DIR__ . '/fixtures/page-with-images.pdf',
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
        $doc = new Document();
        $page = $doc->addPage();

        // JPEG RGB - 32x16 cyan-magenta gradient.
        $jpegSrc = imagecreatetruecolor(32, 16);
        self::assertNotFalse($jpegSrc);
        for ($x = 0; $x < 32; $x++) {
            $r = min(255, max(0, (int) round($x * 255 / 31)));
            $b = min(255, max(0, 255 - $r));
            $color = imagecolorallocate($jpegSrc, $r, 0, $b);
            self::assertNotFalse($color);
            imageline($jpegSrc, $x, 0, $x, 15, $color);
        }
        ob_start();
        imagejpeg($jpegSrc, null, 90);
        $jpegBytes = (string) ob_get_clean();
        $jpegImage = Image::fromBytes($jpegBytes);

        // PNG RGB opaque - 24x12 horizontal red-green-blue strips.
        $pngSrc = imagecreatetruecolor(24, 12);
        self::assertNotFalse($pngSrc);
        $red = imagecolorallocate($pngSrc, 255, 0, 0);
        $green = imagecolorallocate($pngSrc, 0, 255, 0);
        $blue = imagecolorallocate($pngSrc, 0, 0, 255);
        self::assertNotFalse($red);
        self::assertNotFalse($green);
        self::assertNotFalse($blue);
        imagefilledrectangle($pngSrc, 0, 0, 23, 3, $red);
        imagefilledrectangle($pngSrc, 0, 4, 23, 7, $green);
        imagefilledrectangle($pngSrc, 0, 8, 23, 11, $blue);
        ob_start();
        imagepng($pngSrc);
        $pngOpaque = (string) ob_get_clean();
        $pngOpaqueImage = Image::fromBytes($pngOpaque);

        // PNG with alpha - 16x16 with a translucent diagonal.
        $pngAlphaSrc = imagecreatetruecolor(16, 16);
        self::assertNotFalse($pngAlphaSrc);
        imagealphablending($pngAlphaSrc, false);
        imagesavealpha($pngAlphaSrc, true);
        $transparent = imagecolorallocatealpha($pngAlphaSrc, 0, 0, 0, 127);
        $semi = imagecolorallocatealpha($pngAlphaSrc, 200, 50, 50, 64);
        self::assertNotFalse($transparent);
        self::assertNotFalse($semi);
        imagefilledrectangle($pngAlphaSrc, 0, 0, 15, 15, $transparent);
        for ($i = 0; $i < 16; $i++) {
            imagesetpixel($pngAlphaSrc, $i, $i, $semi);
            imagesetpixel($pngAlphaSrc, 15 - $i, $i, $semi);
        }
        ob_start();
        imagepng($pngAlphaSrc);
        $pngAlpha = (string) ob_get_clean();
        $pngAlphaImage = Image::fromBytes($pngAlpha);

        // PNG palette - built via TestImageFactory to ensure color type 3.
        $paletteImage = Image::fromBytes(TestImageFactory::pngPalette(width: 8, height: 8));

        $page->image($jpegImage, x: 50, y: 50, w: 200, h: 100);
        $page->image($pngOpaqueImage, x: 50, y: 200, w: 150);
        $page->image($pngAlphaImage, x: 250, y: 200, w: 100, h: 100);
        $page->image($paletteImage, x: 400, y: 200);

        return $doc->output();
    }
}
