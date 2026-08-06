<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgMaskUserspaceTest extends TestCase
{
    public function testMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/svg/mask/userspace.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/svg/mask/userspace.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        // userSpaceOnUse: the mask region is in absolute SVG user coordinates,
        // not relative to the masked rect. A white circle in user space at
        // (50, 50) r=30 -> only that circular region of the underlying rect
        // remains visible regardless of where the rect sits.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<mask id="m" maskUnits="userSpaceOnUse" maskContentUnits="userSpaceOnUse" x="0" y="0" width="100" height="100">'
            .   '<rect x="0" y="0" width="100" height="100" fill="black"/>'
            .   '<circle cx="50" cy="50" r="30" fill="white"/>'
            . '</mask>'
            . '</defs>'
            . '<rect x="10" y="10" width="80" height="80" fill="green" mask="url(#m)"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        $doc->getCurrentPage()->image($img, x: 50.0, y: 50.0, w: 200.0);
        return $doc->output();
    }
}
