<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Markdown;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Markdown\Line;
use DragonOfMercy\PhpPdf\Markdown\LineReorderer;
use DragonOfMercy\PhpPdf\Markdown\PositionedSegment;
use DragonOfMercy\PhpPdf\Markdown\StyledRun;
use DragonOfMercy\PhpPdf\Text\Direction;
use PHPUnit\Framework\TestCase;

final class LineReordererTest extends TestCase
{
    /** A measure stub: 1.0 pt per UTF-8 codepoint, font/size-independent. */
    private static function measure(): callable
    {
        return static fn (string $t, Font $f, float $s): float => (float) mb_strlen($t, 'UTF-8');
    }

    private static function makeRun(string $text, ?int $linkGroup = null): StyledRun
    {
        return new StyledRun($text, Font::helvetica(), Color::rgb(0, 0, 0), 10.0, false, $linkGroup === null ? null : 'http://x', $linkGroup);
    }

    private static function makeSeg(StyledRun $run, float $xOffset, float $width): PositionedSegment
    {
        return new PositionedSegment($run, $xOffset, $width);
    }

    public function testLtrLineWithoutRtlReturnedUnchanged(): void
    {
        $run = self::makeRun('hello');
        $line = new Line([self::makeSeg($run, 0.0, 5.0)], 12.0);
        $out = LineReorderer::reorder($line, Direction::LTR, self::measure());
        self::assertCount(1, $out->segments);
        self::assertSame('hello', $out->segments[0]->run->text);
        self::assertSame(0.0, $out->segments[0]->xOffsetPt);
    }

    public function testRtlBaseReversesAPureHebrewSegment(): void
    {
        $run = self::makeRun("\u{05D0}\u{05D1}"); // alef, bet
        $line = new Line([self::makeSeg($run, 0.0, 2.0)], 12.0);
        $out = LineReorderer::reorder($line, Direction::RTL, self::measure());
        $visual = '';
        foreach ($out->segments as $s) {
            $visual .= $s->run->text;
        }
        self::assertSame("\u{05D1}\u{05D0}", $visual);
    }

    public function testRtlBaseOrdersTwoStyledSegmentsRightToLeft(): void
    {
        $a = self::makeRun("\u{05D0}\u{05D1}");
        $b = self::makeRun("\u{05D2}\u{05D3}");
        $line = new Line([self::makeSeg($a, 0.0, 2.0), self::makeSeg($b, 2.0, 2.0)], 12.0);
        $out = LineReorderer::reorder($line, Direction::RTL, self::measure());
        $visual = '';
        foreach ($out->segments as $s) {
            $visual .= $s->run->text;
        }
        self::assertSame("\u{05D3}\u{05D2}\u{05D1}\u{05D0}", $visual);
        self::assertSame(0.0, $out->segments[0]->xOffsetPt);
    }

    public function testLinkGroupPreservedAcrossReorder(): void
    {
        $run = self::makeRun("\u{05D0}\u{05D1}", linkGroup: 7);
        $line = new Line([self::makeSeg($run, 0.0, 2.0)], 12.0);
        $out = LineReorderer::reorder($line, Direction::RTL, self::measure());
        self::assertSame(7, $out->segments[0]->run->linkGroup);
        self::assertSame('http://x', $out->segments[0]->run->url);
    }
}
