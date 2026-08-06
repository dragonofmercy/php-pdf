<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgMaskGroupTest extends TestCase
{
    public function testMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg/mask/group.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/svg/mask/group.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        // Mask applied to a <g> containing two shapes. The mask must affect both.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<mask id="m" maskUnits="userSpaceOnUse" x="0" y="0" width="100" height="100">'
            .   '<rect x="0" y="0" width="100" height="100" fill="black"/>'
            .   '<rect x="0" y="40" width="100" height="20" fill="white"/>'
            . '</mask>'
            . '</defs>'
            . '<g mask="url(#m)">'
            .   '<rect x="0" y="0" width="100" height="50" fill="red"/>'
            .   '<rect x="0" y="50" width="100" height="50" fill="blue"/>'
            . '</g>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 200.0);
        return $doc->output();
    }
}
