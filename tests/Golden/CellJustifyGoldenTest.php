<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\TextAlign;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class CellJustifyGoldenTest extends TestCase
{
    private const string LOREM = 'Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore et dolore magna aliqua ut enim ad minim veniam.';

    // --- Standard font justified cell ---

    public static function buildStandard(): string
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);
        $page->cell(w: 220.0, text: self::LOREM, align: TextAlign::JUSTIFY);
        return $doc->output();
    }

    public function testStandardMatchesFixture(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/page/cell-justify.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildStandard(), 'cell-justify.pdf diverges; regenerate if intended.');
    }

    public function testStandardPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/page/cell-justify.pdf');
    }

    // --- Custom font (FreeSans TTF) justified cell ---

    public static function buildCustom(): string
    {
        $doc = new Document(Unit::PT);
        $doc->registerFontFamily('FS', regular: __DIR__ . '/assets/fonts/FreeSans.ttf');
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 11.0);
        $page->cell(w: 220.0, text: self::LOREM, align: TextAlign::JUSTIFY);
        return $doc->output();
    }

    public function testCustomMatchesFixture(): void
    {
        if (!is_file(__DIR__ . '/assets/fonts/FreeSans.ttf')) {
            self::markTestSkipped('FreeSans.ttf asset absent');
        }
        $expected = file_get_contents(__DIR__ . '/fixtures/page/cell-justify-custom.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildCustom(), 'cell-justify-custom.pdf diverges; regenerate if intended.');
    }

    public function testCustomPassesQpdfCheck(): void
    {
        if (!is_file(__DIR__ . '/assets/fonts/FreeSans.ttf')) {
            self::markTestSkipped('FreeSans.ttf asset absent');
        }
        Qpdf::assertCheck(__DIR__ . '/fixtures/page/cell-justify-custom.pdf');
    }
}
