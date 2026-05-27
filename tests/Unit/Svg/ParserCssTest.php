<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgNode;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use PHPUnit\Framework\TestCase;

final class ParserCssTest extends TestCase
{
    private function firstRect(SvgNode $node): ?SvgRect
    {
        if ($node instanceof SvgRect) {
            return $node;
        }
        if ($node instanceof SvgGroup) {
            foreach ($node->children as $child) {
                $found = $this->firstRect($child);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private function parseRect(string $svg): SvgRect
    {
        $meta = Parser::parse($svg);
        $rect = $this->firstRect($meta->root);
        self::assertInstanceOf(SvgRect::class, $rect);
        return $rect;
    }

    public function testTypeSelectorFillsShape(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<style>rect { fill: #ff0000 }</style>'
            . '<rect x="0" y="0" width="10" height="10"/>'
            . '</svg>';
        self::assertEquals(SvgColor::fromBytes(255, 0, 0), $this->parseRect($svg)->paint()->fill);
    }

    public function testClassSelectorBeatsPresentationAttribute(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<style>.hl { fill: #0000ff }</style>'
            . '<rect class="hl" fill="#00ff00" x="0" y="0" width="10" height="10"/>'
            . '</svg>';
        self::assertEquals(SvgColor::fromBytes(0, 0, 255), $this->parseRect($svg)->paint()->fill);
    }

    public function testInlineStyleBeatsStylesheet(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<style>.hl { fill: #0000ff }</style>'
            . '<rect class="hl" style="fill: #ff00ff" x="0" y="0" width="10" height="10"/>'
            . '</svg>';
        self::assertEquals(SvgColor::fromBytes(255, 0, 255), $this->parseRect($svg)->paint()->fill);
    }

    public function testIdSelectorBeatsClass(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<style>.hl { fill: #ff0000 } #target { fill: #00ff00 }</style>'
            . '<rect id="target" class="hl" x="0" y="0" width="10" height="10"/>'
            . '</svg>';
        self::assertEquals(SvgColor::fromBytes(0, 255, 0), $this->parseRect($svg)->paint()->fill);
    }

    public function testRootSvgCssInheritedByChild(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<style>svg { fill: #226622 }</style>'
            . '<rect x="0" y="0" width="10" height="10"/>'
            . '</svg>';
        self::assertEquals(SvgColor::fromBytes(34, 102, 34), $this->parseRect($svg)->paint()->fill);
    }

    public function testCdataStyleIsParsed(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<style><![CDATA[ rect { fill: #ff0000 } ]]></style>'
            . '<rect x="0" y="0" width="10" height="10"/>'
            . '</svg>';
        self::assertEquals(SvgColor::fromBytes(255, 0, 0), $this->parseRect($svg)->paint()->fill);
    }

    public function testNoStyleElementLeavesShapeDefault(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<rect x="0" y="0" width="10" height="10"/>'
            . '</svg>';
        self::assertEquals(SvgColor::black(), $this->parseRect($svg)->paint()->fill);
    }
}
