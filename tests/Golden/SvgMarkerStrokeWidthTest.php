<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgMarkerStrokeWidthTest extends TestCase
{
    public function testSvgMarkerStrokeWidthMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg/marker/strokewidth.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgMarkerStrokeWidthPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/svg/marker/strokewidth.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50">'
            . '<defs>'
            . '<marker id="cap" markerWidth="3" markerHeight="3" refX="0" refY="1.5">'
            . '<rect width="3" height="3" fill="#0066cc"/>'
            . '</marker>'
            . '</defs>'
            . '<line x1="10" y1="15" x2="90" y2="15" stroke="#000" stroke-width="1" marker-end="url(#cap)"/>'
            . '<line x1="10" y1="35" x2="90" y2="35" stroke="#000" stroke-width="4" marker-end="url(#cap)"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 240.0);
        return $doc->output();
    }
}
