<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Css;

use DragonOfMercy\PhpPdf\Svg\Css\CssParser;
use PHPUnit\Framework\TestCase;

final class CssParserTest extends TestCase
{
    public function testTypeRule(): void
    {
        $sheet = CssParser::parse('rect { fill: red; }');
        self::assertSame(['fill' => 'red'], $sheet->declarationsFor('rect', [], null));
    }

    public function testClassAndIdRules(): void
    {
        $sheet = CssParser::parse('.hl { fill: blue } #only { stroke: green }');
        self::assertSame(['fill' => 'blue'], $sheet->declarationsFor('rect', ['hl'], null));
        self::assertSame(['stroke' => 'green'], $sheet->declarationsFor('rect', [], 'only'));
    }

    public function testCompoundSelector(): void
    {
        $sheet = CssParser::parse('rect.hl { fill: blue }');
        self::assertSame(['fill' => 'blue'], $sheet->declarationsFor('rect', ['hl'], null));
        self::assertSame([], $sheet->declarationsFor('circle', ['hl'], null));
    }

    public function testCommaGroupExpandsToMultipleSelectors(): void
    {
        $sheet = CssParser::parse('.a, .b { fill: red }');
        self::assertSame(['fill' => 'red'], $sheet->declarationsFor('rect', ['a'], null));
        self::assertSame(['fill' => 'red'], $sheet->declarationsFor('rect', ['b'], null));
    }

    public function testCommentsAreStripped(): void
    {
        $sheet = CssParser::parse("/* leading */ rect { fill: red; /* inline */ stroke: blue }\n/* trailing */");
        self::assertSame(['fill' => 'red', 'stroke' => 'blue'], $sheet->declarationsFor('rect', [], null));
    }

    public function testImportantTokenStripped(): void
    {
        $sheet = CssParser::parse('rect { fill: red !important }');
        self::assertSame(['fill' => 'red'], $sheet->declarationsFor('rect', [], null));
    }

    public function testUniversalSelector(): void
    {
        $sheet = CssParser::parse('* { fill: red }');
        self::assertSame(['fill' => 'red'], $sheet->declarationsFor('anything', [], null));
    }

    public function testAtRuleBlockDiscarded(): void
    {
        $sheet = CssParser::parse('@media screen { rect { fill: red } } circle { fill: blue }');
        self::assertSame([], $sheet->declarationsFor('rect', [], null));
        self::assertSame(['fill' => 'blue'], $sheet->declarationsFor('circle', [], null));
    }

    public function testBlocklessAtStatementDiscarded(): void
    {
        $sheet = CssParser::parse('@import url(other.css); rect { fill: red }');
        self::assertSame(['fill' => 'red'], $sheet->declarationsFor('rect', [], null));
    }

    public function testUnsupportedSelectorSkipped(): void
    {
        $sheet = CssParser::parse('g rect { fill: red } rect:hover { fill: green } rect { fill: blue }');
        self::assertSame(['fill' => 'blue'], $sheet->declarationsFor('rect', [], null));
    }

    public function testMalformedRuleSkipped(): void
    {
        $sheet = CssParser::parse('rect { fill } circle { fill: blue }');
        self::assertSame([], $sheet->declarationsFor('rect', [], null));
        self::assertSame(['fill' => 'blue'], $sheet->declarationsFor('circle', [], null));
    }

    public function testMultipleBlocksSourceOrderContinues(): void
    {
        $sheet = CssParser::parse(".c { fill: first }\n.c { fill: second }");
        self::assertSame(['fill' => 'second'], $sheet->declarationsFor('rect', ['c'], null));
    }

    public function testEmptyInputReturnsEmptyStylesheet(): void
    {
        self::assertSame([], CssParser::parse('')->declarationsFor('rect', [], null));
        self::assertSame([], CssParser::parse("   \n\t ")->declarationsFor('rect', [], null));
    }
}
