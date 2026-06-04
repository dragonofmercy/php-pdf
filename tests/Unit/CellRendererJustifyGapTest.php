<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\TextAlign;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class CellRendererJustifyGapTest extends TestCase
{
    public function testJustifiedLineHasNoPhantomTrailingGap(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12.0);
        $page->cell(w: 120.0, text: 'one two three four five six seven eight nine ten', align: TextAlign::JUSTIFY);
        $bytes = $page->contentStream()->bytes();

        preg_match_all('/\[(.*?)\] TJ/', $bytes, $m);
        self::assertNotEmpty($m[1], 'expected at least one TJ array');

        foreach ($m[1] as $body) {
            // No empty literal segment "()" (the phantom trailing segment).
            self::assertStringNotContainsString('()', $body, "TJ array has an empty segment: $body");
            // Array must END with a string element, not an adjustment number.
            self::assertMatchesRegularExpression('/\)\s*$/', $body, "TJ array ends with an adjustment: $body");
            // #adjustments (negative numbers) must equal #word-gaps = (#string elements - 1).
            $strings = preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/', $body);
            $adjustments = preg_match_all('/(?<=\))\s*-?\d/', $body);
            self::assertSame($strings - 1, $adjustments, "adjustment count != gaps in: $body");
        }
    }
}
