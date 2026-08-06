<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Table\Cell;
use DragonOfMercy\PhpPdf\Table\Column;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Text\Direction;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class RtlHebrewTableTest extends TestCase
{
    public function testMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/rtl/hebrew-table.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/rtl/hebrew-table.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $doc->registerFontFamily('FS', regular: __DIR__ . '/assets/fonts/FreeSans.ttf');
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 12);

        $columns = [
            Column::of('name', 'Name')->fill(),
            Column::of('city', 'City')->width(50.0),
        ];
        // shalom / yerushalayim, both RTL.
        $rows = [
            [
                'name' => Cell::of("\u{05E9}\u{05DC}\u{05D5}\u{05DD}")->direction(Direction::RTL),
                'city' => Cell::of("\u{05D9}\u{05E8}\u{05D5}\u{05E9}\u{05DC}\u{05D9}\u{05DD}")->direction(Direction::RTL),
            ],
        ];
        $page->table($columns, $rows, x: 20, y: 20, width: 120);

        return $doc->output();
    }
}
