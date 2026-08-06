<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgTextSimpleTest extends TestCase
{
    private const string FIXTURE = 'svg/text/simple.pdf';

    public function testSvgTextSimpleMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/' . self::FIXTURE);
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgTextSimplePassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/' . self::FIXTURE);
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 100">'
            . '<text x="10" y="40" font-family="sans-serif" font-size="24" fill="#1133aa">Hello PDF</text>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 40.0, y: 40.0, w: 300.0);
        return $doc->output();
    }
}
