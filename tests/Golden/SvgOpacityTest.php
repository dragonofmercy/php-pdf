<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgOpacityTest extends TestCase
{
    public function testSvgOpacityMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg/basic/opacity.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testSvgOpacityPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/svg/basic/opacity.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">'
            . '<rect x="0" y="0" width="200" height="200" fill="white"/>'
            . '<rect x="10" y="10" width="100" height="100" fill="red" fill-opacity="0.5"/>'
            . '<rect x="50" y="50" width="100" height="100" fill="blue" fill-opacity="0.5"/>'
            . '<rect x="90" y="90" width="100" height="100" fill="green" fill-opacity="0.5"/>'
            . '<rect x="10" y="150" width="80" height="40" fill="none" stroke="purple" stroke-width="4" stroke-opacity="0.3" opacity="0.8"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 300.0);
        return $doc->output();
    }
}
