<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgClipUnionTest extends TestCase
{
    private const string FIXTURE = 'svg/clip/union.pdf';

    public function testSvgClipUnionMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/' . self::FIXTURE);
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgClipUnionPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/' . self::FIXTURE);
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<clipPath id="c"><circle cx="38" cy="50" r="30"/><circle cx="62" cy="50" r="30"/></clipPath>'
            . '<rect x="0" y="0" width="100" height="100" fill="#114488" clip-path="url(#c)"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 40.0, y: 40.0, w: 300.0);
        return $doc->output();
    }
}
