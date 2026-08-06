<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgFilterCompositeArithmeticTest extends TestCase
{
    public function testSvgFilterCompositeArithmeticMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg/filter/composite-arithmetic.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgFilterCompositeArithmeticPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/svg/filter/composite-arithmetic.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<filter id="f" x="-10%" y="-10%" width="120%" height="120%">'
            .   '<feFlood flood-color="#ffaa00" flood-opacity="0.8" result="flood"/>'
            .   '<feComposite in="flood" in2="SourceGraphic" operator="arithmetic" k1="0.5" k2="0.3" k3="0.3" k4="0"/>'
            . '</filter>'
            . '</defs>'
            . '<rect x="20" y="20" width="60" height="60" fill="#2244aa" filter="url(#f)"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 200.0);
        return $doc->output();
    }
}
