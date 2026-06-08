<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use PHPUnit\Framework\TestCase;

final class MarkdownImageLinkTaggingTest extends TestCase
{
    private const string PNG = __DIR__ . '/assets/png-opaque-rgb-24x12.png';

    public function testMarkdownImageLinkProducesLinkFigureObjr(): void
    {
        $doc = new Document();
        $doc->enableTagging('en-US');
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->markdown('[![Company logo](' . self::PNG . ')](https://example.com)');
        $pdf = $doc->output();

        self::assertMatchesRegularExpression('#/S\s*/Link\b#', $pdf, 'a <Link> structure element');
        self::assertMatchesRegularExpression('#/S\s*/Figure\b#', $pdf, 'a <Figure> structure element');
        self::assertMatchesRegularExpression('#/Type\s*/OBJR#', $pdf, 'an OBJR leaf');
        self::assertMatchesRegularExpression('#/StructParent\b#', $pdf, '/StructParent on the annotation');
        self::assertMatchesRegularExpression('#/Alt\b#', $pdf, 'the figure carries /Alt');
    }

    public function testMarkdownBlockImageWithoutLinkStillCarriesAlt(): void
    {
        $doc = new Document();
        $doc->enableTagging('en-US');
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->markdown('![Just an image](' . self::PNG . ')');
        $pdf = $doc->output();

        self::assertMatchesRegularExpression('#/S\s*/Figure\b#', $pdf, 'a <Figure> structure element');
        self::assertMatchesRegularExpression('#/Alt\b#', $pdf, 'the figure carries /Alt (forwarded from the markdown image alt)');
        self::assertDoesNotMatchRegularExpression('#/S\s*/Link\b#', $pdf, 'no link element when there is no link');
    }
}
