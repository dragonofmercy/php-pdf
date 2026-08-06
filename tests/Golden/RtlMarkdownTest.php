<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\NextPosition;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Text\Direction;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class RtlMarkdownTest extends TestCase
{
    public function testMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/markdown/rtl-blocks.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildPdfBytes(),
            'Output diverges from fixture. If intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/markdown/rtl-blocks.pdf');
    }

    public static function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $doc->registerFontFamily('FS', regular: __DIR__ . '/assets/fonts/FreeSerif.ttf');
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 14);

        // Hebrew "shalom olam", Arabic "namir bila", Hebrew heading, mixed
        // Arabic + Latin number, and a Hebrew line with inline code in the
        // middle. The blank lines split them into separate Markdown blocks.
        $hebrewParagraph = "\u{05E9}\u{05DC}\u{05D5}\u{05DD} \u{05E2}\u{05D5}\u{05DC}\u{05DD}";
        $arabicParagraph = "\u{0646}\u{0645}\u{0631} \u{0628}\u{0644}\u{0627}";
        $heading = "# " . "\u{05E9}\u{05DC}\u{05D5}\u{05DD}";
        $mixed = "\u{0646}\u{0645}\u{0631} 2026";
        $inlineCode = "\u{05E9}\u{05DC}\u{05D5}\u{05DD} `code` \u{05E2}\u{05D5}\u{05DC}\u{05DD}";

        $md = implode("\n\n", [
            $hebrewParagraph,
            $arabicParagraph,
            $heading,
            $mixed,
            $inlineCode,
        ]);

        $page->markdown($md, 20.0, 20.0, 170.0, null, NextPosition::BELOW, Direction::RTL);

        return $doc->output();
    }

    public function testListsMatchFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/markdown/rtl-lists.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            self::buildListsPdfBytes(),
            'Output diverges from fixture. If intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testListsPassQpdfCheck(): void
    {
        Qpdf::assertCheck(__DIR__ . '/fixtures/markdown/rtl-lists.pdf');
    }

    public static function buildListsPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $doc->registerFontFamily('FS', regular: __DIR__ . '/assets/fonts/FreeSerif.ttf');
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 14);

        // RTL bullet list (Hebrew/Arabic items), an ordered list, and a
        // blockquote of Hebrew text: markers and the quote bar must mirror to
        // the right while content stays right-aligned in the left box.
        $bulletA = "\u{05E9}\u{05DC}\u{05D5}\u{05DD}";          // Hebrew "shalom"
        $bulletB = "\u{0646}\u{0645}\u{0631}";                  // Arabic "namir"
        $bulletC = "\u{05E2}\u{05D5}\u{05DC}\u{05DD}";          // Hebrew "olam"
        $orderA = "\u{05D0}\u{05D7}\u{05EA}";                   // Hebrew "ehad" (one)
        $orderB = "\u{05E9}\u{05EA}\u{05D9}\u{05DD}";           // Hebrew "shtayim" (two)
        $quote = "\u{05E9}\u{05DC}\u{05D5}\u{05DD} \u{05E2}\u{05D5}\u{05DC}\u{05DD}"; // "shalom olam"

        $md = implode("\n", [
            "- " . $bulletA,
            "- " . $bulletB,
            "- " . $bulletC,
            "",
            "1. " . $orderA,
            "2. " . $orderB,
            "",
            "> " . $quote,
        ]);

        $page->markdown($md, 20.0, 20.0, 170.0, null, NextPosition::BELOW, Direction::RTL);

        return $doc->output();
    }
}
