<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use DragonOfMercy\PhpPdf\Svg\SvgNode;
use DragonOfMercy\PhpPdf\Svg\SvgText;
use DragonOfMercy\PhpPdf\Svg\SvgTextSpan;
use DragonOfMercy\PhpPdf\Svg\TextAnchor;
use PHPUnit\Framework\TestCase;

final class SvgTextNodeTest extends TestCase
{
    public function testSvgTextIsAnSvgNodeCarryingSpans(): void
    {
        $span = new SvgTextSpan(
            text: 'Hi',
            font: Font::helvetica(),
            fontSize: 12.0,
            fill: SvgColor::black(),
            fillOpacity: 1.0,
            stroke: null,
            strokeOpacity: 1.0,
            strokeWidth: 1.0,
            anchor: TextAnchor::START,
            x: 10.0,
            y: 20.0,
            dx: 0.0,
            dy: 0.0,
        );
        $text = new SvgText(null, [$span]);
        self::assertInstanceOf(SvgNode::class, $text);
        self::assertCount(1, $text->spans);
        self::assertSame('Hi', $text->spans[0]->text);
        self::assertSame(10.0, $text->spans[0]->x);
    }
}
