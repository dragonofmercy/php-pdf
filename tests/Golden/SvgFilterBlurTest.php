<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgFilterBlurTest extends TestCase
{
    public function testSvgFilterBlurMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg/filter/blur.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgFilterBlurPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/svg/filter/blur.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<filter id="f" x="-20%" y="-20%" width="140%" height="140%">'
            .   '<feGaussianBlur stdDeviation="4"/>'
            . '</filter>'
            . '</defs>'
            . '<rect x="25" y="25" width="50" height="50" fill="#2266cc" filter="url(#f)"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 200.0);
        return $doc->output();
    }
}
