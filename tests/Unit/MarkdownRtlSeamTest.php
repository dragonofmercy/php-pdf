<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Text\Arabic\ArabicShaper;
use PHPUnit\Framework\TestCase;

final class MarkdownRtlSeamTest extends TestCase
{
    private const string FONT = __DIR__ . '/../Golden/assets/fonts/FreeSerif.ttf';

    public function testMarkdownShapesArabicBeforeRendering(): void
    {
        $word = "\u{0646}\u{0645}\u{0631}"; // noon meem reh
        $shaped = ArabicShaper::shape($word);

        $a = self::renderMarkdownBytes($word);
        $b = self::renderMarkdownBytes($shaped);

        self::assertSame($b, $a, 'markdown() must shape Arabic so base and pre-shaped inputs render identically');
        self::assertNotSame($word, $shaped);
    }

    private static function renderMarkdownBytes(string $text): string
    {
        $doc = new Document();
        $doc->registerFontFamily('FS', regular: self::FONT);
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 12.0);
        // Signature: markdown(string $markdown, ?float $x, ?float $y, ?float $width, ?MarkdownStyle $style, NextPosition $ln, ?Direction $direction)
        $page->markdown($text, 20.0, 20.0, 160.0);

        return $doc->output();
    }
}
