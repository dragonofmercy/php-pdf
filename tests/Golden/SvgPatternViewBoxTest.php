<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgPatternViewBoxTest extends TestCase
{
    public function testSvgPatternViewBoxMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg/pattern/viewbox.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgPatternViewBoxPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/svg/pattern/viewbox.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 80">'
            . '<defs>'
            . '<pattern id="bricks" patternUnits="userSpaceOnUse" x="0" y="0" width="20" height="20" viewBox="0 0 10 10">'
            . '<rect width="10" height="10" fill="#fff5e1"/>'
            . '<rect x="0" y="0" width="9.5" height="4" fill="#a23"/>'
            . '<rect x="0" y="5" width="4.25" height="4" fill="#a23"/>'
            . '<rect x="5.25" y="5" width="4.25" height="4" fill="#a23"/>'
            . '</pattern>'
            . '</defs>'
            . '<rect width="120" height="80" fill="url(#bricks)" stroke="#000" stroke-width="1"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 240.0);
        return $doc->output();
    }
}
