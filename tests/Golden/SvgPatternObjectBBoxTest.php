<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgPatternObjectBBoxTest extends TestCase
{
    public function testSvgPatternObjectBBoxMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg/pattern/objectbbox.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgPatternObjectBBoxPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/svg/pattern/objectbbox.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 60">'
            . '<defs>'
            . '<pattern id="grid" width="0.1" height="0.1">'
            . '<rect width="0.1" height="0.1" fill="#fee"/>'
            . '<circle cx="0.05" cy="0.05" r="0.03" fill="#066"/>'
            . '</pattern>'
            . '</defs>'
            . '<rect width="100" height="60" fill="url(#grid)" stroke="#000" stroke-width="1"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 240.0);
        return $doc->output();
    }
}
