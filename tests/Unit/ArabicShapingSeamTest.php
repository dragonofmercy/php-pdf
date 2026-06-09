<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Text\Arabic\ArabicShaper;
use PHPUnit\Framework\TestCase;

final class ArabicShapingSeamTest extends TestCase
{
    private const string FONT = __DIR__ . '/../Golden/assets/fonts/FreeSerif.ttf';

    public function testCellAutoWidthMeasuresShapedText(): void
    {
        // noon meem reh: a dual-joining word whose shaped forms differ in width.
        $word = "\u{0646}\u{0645}\u{0631}";

        $doc = new Document();
        $doc->registerFontFamily('FS', regular: self::FONT);
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 14.0);

        // stringWidth() is the RAW measure (no shaping) in document units.
        $shapedW = $page->stringWidth(ArabicShaper::shape($word));
        $unshapedW = $page->stringWidth($word);

        // Sanity: shaping changes the measured width for this word.
        self::assertGreaterThan(0.01, abs($shapedW - $unshapedW));

        // Auto width (no w), padding 0: effectiveWidth is exactly the line width.
        $result = $page->cell(x: 10.0, y: 10.0, text: $word, padding: 0.0);

        self::assertEqualsWithDelta($shapedW, $result->effectiveWidth, 0.01);
        self::assertGreaterThan(0.01, abs($result->effectiveWidth - $unshapedW));
    }
}
