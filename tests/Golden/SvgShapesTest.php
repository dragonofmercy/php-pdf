<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgShapesTest extends TestCase
{
    public function testSvgShapesMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg/basic/shapes.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgShapesPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/svg/basic/shapes.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 200">'
            . '<rect x="10" y="10" width="80" height="60" fill="red"/>'
            . '<circle cx="150" cy="40" r="30" fill="blue"/>'
            . '<ellipse cx="250" cy="40" rx="40" ry="25" fill="green"/>'
            . '<line x1="10" y1="110" x2="90" y2="110" stroke="orange" stroke-width="3"/>'
            . '<polygon points="150,80 180,140 120,140" fill="purple"/>'
            . '<polyline points="200,80 240,100 220,140 260,140" fill="none" stroke="teal" stroke-width="2"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 400.0);
        return $doc->output();
    }
}
