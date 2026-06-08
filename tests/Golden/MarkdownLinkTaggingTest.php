<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use PHPUnit\Framework\TestCase;

final class MarkdownLinkTaggingTest extends TestCase
{
    private static function taggedMarkdown(string $md, float $width = 170.0): string
    {
        $doc = new Document();
        $doc->enableTagging('en-US');
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->markdown($md, width: $width);

        return $doc->output();
    }

    public function testInlineLinkProducesTaggedLinkElement(): void
    {
        $pdf = self::taggedMarkdown('See [the site](https://example.com) now.');

        self::assertSame(1, preg_match_all('#/S\s*/Link\b#', $pdf), 'exactly one <Link> structure element');
        self::assertMatchesRegularExpression('#/Type\s*/OBJR#', $pdf, 'an OBJR leaf is present');
        self::assertMatchesRegularExpression('#/Subtype\s*/Link#', $pdf, 'a link annotation is present');
        self::assertMatchesRegularExpression('#/StructParent\b#', $pdf, 'the annotation carries /StructParent');
        self::assertMatchesRegularExpression('#/Contents\b#', $pdf, 'the annotation carries /Contents');
        self::assertMatchesRegularExpression('#/F\s+4\b#', $pdf, 'the annotation carries the Print flag /F 4');
    }

    public function testTwoLinksSameUrlProduceTwoLinkElements(): void
    {
        $pdf = self::taggedMarkdown('Go [here](https://example.com) and [there](https://example.com).');

        self::assertSame(2, preg_match_all('#/S\s*/Link\b#', $pdf), 'two distinct <Link> elements');
    }
}
