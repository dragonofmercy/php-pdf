<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgMaskTextTest extends TestCase
{
    public function testSvgMaskTextMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg/mask/text.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgMaskTextPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/svg/mask/text.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        // A colored rectangle masked by white text -> only the text-shaped region
        // remains visible (the surrounding mask area is black -> invisible).
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 80">'
            . '<defs>'
            . '<mask id="m" maskUnits="userSpaceOnUse" x="0" y="0" width="200" height="80">'
            .   '<rect x="0" y="0" width="200" height="80" fill="black"/>'
            .   '<text x="100" y="55" font-family="Helvetica" font-size="48" text-anchor="middle" fill="white">SVG</text>'
            . '</mask>'
            . '</defs>'
            . '<rect x="0" y="0" width="200" height="80" fill="#3366cc" mask="url(#m)"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 30.0, y: 100.0, w: 400.0);
        return $doc->output();
    }
}
