<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class MarkdownCellTest extends TestCase
{
    public function testMarkdownCellMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/markdown/cell.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testMarkdownCellPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/markdown/cell.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11);
        $page->cell(
            x: 20,
            y: 20,
            w: 120,
            text: MarkdownSample::TEXT,
            border: Border::all(),
            fill: Color::rgb(250, 250, 250),
            markdown: true,
        );

        return $doc->output();
    }
}
