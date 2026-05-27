<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\SvgText;
use PHPUnit\Framework\TestCase;

final class ParserTextTest extends TestCase
{
    private function firstText(string $svg): SvgText
    {
        $meta = Parser::parse($svg);
        $result = $this->findFirstText($meta->root->children);
        if ($result !== null) {
            return $result;
        }
        self::fail('No SvgText node parsed');
    }

    /**
     * @param list<\DragonOfMercy\PhpPdf\Svg\SvgNode> $nodes
     */
    private function findFirstText(array $nodes): ?SvgText
    {
        foreach ($nodes as $child) {
            if ($child instanceof SvgText) {
                return $child;
            }
            if ($child instanceof \DragonOfMercy\PhpPdf\Svg\SvgGroup) {
                $found = $this->findFirstText($child->children);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    public function testSimpleTextProducesOneSpan(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<text x="10" y="20" font-family="sans-serif" font-size="14">Hello</text></svg>';
        $text = $this->firstText($svg);
        self::assertCount(1, $text->spans);
        self::assertSame('Hello', $text->spans[0]->text);
        self::assertSame(10.0, $text->spans[0]->x);
        self::assertSame(20.0, $text->spans[0]->y);
        self::assertSame(14.0, $text->spans[0]->fontSize);
        self::assertSame('Helvetica', $text->spans[0]->font->pdfName());
    }

    public function testTspanInheritsAndOverrides(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<text x="5" y="10" font-size="12" fill="#ff0000">A'
            . '<tspan font-weight="bold" dx="3">B</tspan></text></svg>';
        $text = $this->firstText($svg);
        self::assertCount(2, $text->spans);
        self::assertSame('A', $text->spans[0]->text);
        self::assertSame('Helvetica', $text->spans[0]->font->pdfName());
        self::assertNotNull($text->spans[0]->fill);
        self::assertSame(1.0, $text->spans[0]->fill->r);
        self::assertSame('B', $text->spans[1]->text);
        self::assertSame('Helvetica-Bold', $text->spans[1]->font->pdfName());
        self::assertSame(3.0, $text->spans[1]->dx);
        self::assertNull($text->spans[1]->x);
    }

    public function testWhitespaceIsCollapsedAndEdgeTrimmed(): void
    {
        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 100 100\">"
            . "<text x=\"0\" y=\"0\">  hello\n   world  </text></svg>";
        $text = $this->firstText($svg);
        self::assertSame('hello world', $text->spans[0]->text);
    }

    public function testFontFamilyInheritedFromGroup(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<g font-family="monospace"><text x="0" y="0">x</text></g></svg>';
        $text = $this->firstText($svg);
        self::assertSame('Courier', $text->spans[0]->font->pdfName());
    }

    public function testEmptyTextProducesNoSpansAndIsSkipped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<text x="0" y="0">   </text></svg>';
        $meta = Parser::parse($svg);
        foreach ($meta->root->children as $child) {
            self::assertNotInstanceOf(SvgText::class, $child);
        }
        self::assertEmpty($meta->root->children);
    }

    public function testTextNodeAfterTspanIsAContinuationNotRepositioned(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<text x="5" y="10"><tspan dx="2">A</tspan>B</text></svg>';
        $text = $this->firstText($svg);
        self::assertCount(2, $text->spans);
        // The tspan is the first child. The tspan's own collectTextSpans finds no x/y on the
        // tspan element itself, so span[0] ('A') has x=null from the tspan's scope.
        // The parent's x=5/y=10 are "pending" on the parent, but the tspan element is a
        // separate collectTextSpans call that does not consume the parent's position anchor.
        self::assertSame('A', $text->spans[0]->text);
        self::assertNull($text->spans[0]->x);
        // The key regression: the trailing 'B' (a DOMText sibling after the tspan) must be a
        // continuation - no absolute x reset, no dx carry-over.
        self::assertSame('B', $text->spans[1]->text);
        self::assertNull($text->spans[1]->x);
        self::assertSame(0.0, $text->spans[1]->dx);
    }
}
