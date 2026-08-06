<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgGradientStopAlphaRadialTest extends TestCase
{
    public function testMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg/gradient/stop-alpha-radial.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/svg/gradient/stop-alpha-radial.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<radialGradient id="g" cx="0.5" cy="0.5" r="0.5">'
            .   '<stop offset="0" stop-color="blue" stop-opacity="1"/>'
            .   '<stop offset="1" stop-color="blue" stop-opacity="0"/>'
            . '</radialGradient>'
            . '</defs>'
            . '<rect x="0" y="0" width="100" height="100" fill="#ffff66"/>'
            . '<rect x="0" y="0" width="100" height="100" fill="url(#g)"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 200.0);
        return $doc->output();
    }
}
