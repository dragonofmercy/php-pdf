<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgImageJpegTest extends TestCase
{
    public function testSvgImageJpegMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg/image/jpeg.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgImageJpegPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/svg/image/jpeg.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        // Use the committed real JPEG asset (deterministic bytes; qpdf-valid JPEG stream)
        $jpegBytes = file_get_contents(__DIR__ . '/assets/jpeg-rgb-32x16.jpg');
        assert(is_string($jpegBytes));
        $uri = 'data:image/jpeg;base64,' . base64_encode($jpegBytes);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><image x="10" y="10" width="80" height="80" href="' . $uri . '"/></svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 200.0);
        return $doc->output();
    }
}
