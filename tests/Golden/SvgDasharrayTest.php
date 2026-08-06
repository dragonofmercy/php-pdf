<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgDasharrayTest extends TestCase
{
    public function testSvgDasharrayMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg/basic/dasharray.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgDasharrayPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/svg/basic/dasharray.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 80">'
            . '<line x1="10" y1="20" x2="190" y2="20" stroke="black" stroke-width="2" stroke-dasharray="4 2"/>'
            . '<line x1="10" y1="40" x2="190" y2="40" stroke="blue" stroke-width="2" stroke-dasharray="6 2 1 2"/>'
            . '<line x1="10" y1="60" x2="190" y2="60" stroke="red" stroke-width="2" stroke-dasharray="8" stroke-dashoffset="2"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 400.0);
        return $doc->output();
    }
}
