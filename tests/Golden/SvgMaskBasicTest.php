<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgMaskBasicTest extends TestCase
{
    public function testSvgMaskBasicMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg/mask/basic.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgMaskBasicPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/svg/mask/basic.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        // Red rectangle masked by a radial-feel white-to-black gradient via three
        // overlapping rectangles at progressively brighter values. The center
        // reveals the rect, the edges hide it.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<mask id="m" maskUnits="userSpaceOnUse" x="0" y="0" width="100" height="100">'
            .   '<rect x="0" y="0" width="100" height="100" fill="#444444"/>'
            .   '<rect x="20" y="20" width="60" height="60" fill="#888888"/>'
            .   '<rect x="35" y="35" width="30" height="30" fill="#ffffff"/>'
            . '</mask>'
            . '</defs>'
            . '<rect x="0" y="0" width="100" height="100" fill="red" mask="url(#m)"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 200.0);
        return $doc->output();
    }
}
