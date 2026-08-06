<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class SvgTextCustomVariantFallbackTest extends TestCase
{
    private const string FIXTURE = 'svg/text/custom-variant-fallback.pdf';
    private const string FONTS = __DIR__ . '/assets/fonts';

    public static function fontsPresent(): bool
    {
        return is_file(self::FONTS . '/FreeSans.ttf');
    }

    public function testMatchesFixtureBytes(): void
    {
        if (!self::fontsPresent()) {
            self::markTestSkipped('FreeSans fixtures absent');
        }

        $expected = file_get_contents(__DIR__ . '/fixtures/' . self::FIXTURE);
        self::assertIsString($expected, 'Golden fixture missing - regenerate with tests/Golden/regenerate.php');
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testBoldFallsBackToRegularCustomFaceNotHelvetica(): void
    {
        if (!self::fontsPresent()) {
            self::markTestSkipped('FreeSans fixtures absent');
        }
        $bytes = self::buildPdfBytes();
        self::assertStringContainsString('FreeSans', $bytes, 'Bold must fall back to the registered regular FreeSans face');
        self::assertStringContainsString('Identity-H', $bytes);
        self::assertStringNotContainsString('Helvetica', $bytes, 'Missing bold variant must not fall back to a standard font');
    }

    public function testPassesQpdfCheck(): void
    {
        if (!self::fontsPresent()) {
            self::markTestSkipped('FreeSans fixtures absent');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'phppdf-svg-fallback-') . '.pdf';
        file_put_contents($tmp, self::buildPdfBytes());
        try {
            Qpdf::assertCheck($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public static function buildPdfBytes(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 60">'
            . '<text x="10" y="40" font-family="FS" font-weight="bold" font-size="24" fill="#000">Fallback</text>'
            . '</svg>';
        $doc = new Document(Unit::MM);
        $doc->registerFontFamily('FS', regular: self::FONTS . '/FreeSans.ttf');
        $page = $doc->addPage();
        $page->image(Image::fromBytes($svg), x: 20.0, y: 20.0, w: 100.0);
        return $doc->output();
    }
}
