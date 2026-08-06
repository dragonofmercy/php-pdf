<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgFilterColorMatrixSaturateTest extends TestCase
{
    public function testSvgFilterColorMatrixSaturateMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg/filter/color-matrix-saturate.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgFilterColorMatrixSaturatePassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/svg/filter/color-matrix-saturate.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<filter id="f" x="-10%" y="-10%" width="120%" height="120%">'
            .   '<feColorMatrix type="saturate" values="0.3"/>'
            . '</filter>'
            . '</defs>'
            . '<g filter="url(#f)">'
            .   '<rect x="10" y="10" width="35" height="35" fill="#ff0000"/>'
            .   '<rect x="55" y="10" width="35" height="35" fill="#00cc00"/>'
            .   '<rect x="10" y="55" width="35" height="35" fill="#0000ff"/>'
            .   '<rect x="55" y="55" width="35" height="35" fill="#ffcc00"/>'
            . '</g>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 200.0);
        return $doc->output();
    }
}
