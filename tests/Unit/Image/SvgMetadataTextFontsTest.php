<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Svg\Parser;
use PHPUnit\Framework\TestCase;

final class SvgMetadataTextFontsTest extends TestCase
{
    public function testCollectsDistinctTextFontsAcrossGroups(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<text x="0" y="10" font-family="sans-serif">a</text>'
            . '<g font-family="serif"><text x="0" y="30" font-weight="bold">b</text></g>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $names = array_map(static fn ($f) => $f->pdfName(), $meta->textFonts());
        sort($names);
        self::assertSame(['Helvetica', 'Times-Bold'], $names);
    }

    public function testNoTextMeansNoFonts(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect x="0" y="0" width="5" height="5"/></svg>';
        self::assertSame([], Parser::parse($svg)->textFonts());
    }
}
