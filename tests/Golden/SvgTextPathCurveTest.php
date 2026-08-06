<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgTextPathCurveTest extends TestCase
{
    private const string FIXTURE = 'svg/text/textpath-curve.pdf';

    public static function buildPdfBytes(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 160">'
            . '<defs><path id="p" d="M20,110 Q150,20 280,110"/></defs>'
            . '<text font-size="20" fill="#00aa33"><textPath href="#p" startOffset="8">Text on a curve</textPath></text>'
            . '</svg>';
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $page->image(Image::fromBytes($svg), x: 20.0, y: 20.0, w: 120.0);
        return $doc->output();
    }

    public function testMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/' . self::FIXTURE);
        self::assertIsString($expected, 'Golden fixture missing - regenerate with tests/Golden/regenerate.php');
        self::assertSame($expected, self::buildPdfBytes(), 'Output diverges; if intentional run php tests/Golden/regenerate.php');
    }

    public function testPassesQpdfCheck(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phppdf-textpath-') . '.pdf';
        file_put_contents($tmp, self::buildPdfBytes());
        try {
            Qpdf::assertCheck($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
