<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\PageMargins;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class MarkdownMultiPageTest extends TestCase
{
    public function testMarkdownMultiPageMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/markdown/multipage.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testMarkdownMultiPageSpansAtLeastTwoPages(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/markdown/multipage.pdf');
        self::assertIsString($expected);

        // Count the page leaf objects in the raw bytes. The library emits one
        // "/Type /Page" (note: not "/Pages") per page object.
        $pageCount = preg_match_all('~/Type\s*/Page(?![s])~', $expected);
        self::assertIsInt($pageCount);
        self::assertGreaterThanOrEqual(
            2,
            $pageCount,
            'markdown/multipage.pdf must span at least two pages.',
        );
    }

    public function testMarkdownMultiPagePassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/markdown/multipage.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $doc->setMargins(PageMargins::all(20));
        $doc->setAutoPageBreak(true);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11);
        $page->markdown(MarkdownSample::multipage());

        return $doc->output();
    }
}
