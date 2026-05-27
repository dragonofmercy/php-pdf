<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Css;

use DragonOfMercy\PhpPdf\Svg\Css\CssSelector;
use DragonOfMercy\PhpPdf\Svg\Css\CssStylesheet;
use PHPUnit\Framework\TestCase;

final class CssStylesheetTest extends TestCase
{
    public function testEmptyStylesheetReturnsNoDeclarations(): void
    {
        self::assertSame([], CssStylesheet::empty()->declarationsFor('rect', [], null));
    }

    public function testNoMatchReturnsEmpty(): void
    {
        $sheet = new CssStylesheet([
            ['selector' => new CssSelector('circle', [], null), 'declarations' => ['fill' => 'red'], 'order' => 0],
        ]);
        self::assertSame([], $sheet->declarationsFor('rect', [], null));
    }

    public function testTypeSelectorApplies(): void
    {
        $sheet = new CssStylesheet([
            ['selector' => new CssSelector('rect', [], null), 'declarations' => ['fill' => 'red'], 'order' => 0],
        ]);
        self::assertSame(['fill' => 'red'], $sheet->declarationsFor('rect', [], null));
    }

    public function testIdBeatsClassBeatsType(): void
    {
        $sheet = new CssStylesheet([
            ['selector' => new CssSelector('rect', [], null), 'declarations' => ['fill' => 'fromType'], 'order' => 0],
            ['selector' => new CssSelector(null, ['c'], null), 'declarations' => ['fill' => 'fromClass'], 'order' => 1],
            ['selector' => new CssSelector(null, [], 'i'), 'declarations' => ['fill' => 'fromId'], 'order' => 2],
        ]);
        self::assertSame(['fill' => 'fromId'], $sheet->declarationsFor('rect', ['c'], 'i'));
    }

    public function testSourceOrderBreaksSpecificityTie(): void
    {
        $sheet = new CssStylesheet([
            ['selector' => new CssSelector(null, ['c'], null), 'declarations' => ['fill' => 'first'], 'order' => 0],
            ['selector' => new CssSelector(null, ['c'], null), 'declarations' => ['fill' => 'second'], 'order' => 1],
        ]);
        self::assertSame(['fill' => 'second'], $sheet->declarationsFor('rect', ['c'], null));
    }

    public function testDeclarationsFromDifferentRulesMerge(): void
    {
        $sheet = new CssStylesheet([
            ['selector' => new CssSelector('rect', [], null), 'declarations' => ['fill' => 'red'], 'order' => 0],
            ['selector' => new CssSelector(null, ['c'], null), 'declarations' => ['stroke' => 'blue'], 'order' => 1],
        ]);
        self::assertSame(['fill' => 'red', 'stroke' => 'blue'], $sheet->declarationsFor('rect', ['c'], null));
    }

    public function testCompoundOutranksLowerSpecificity(): void
    {
        $sheet = new CssStylesheet([
            ['selector' => new CssSelector(null, ['c'], null), 'declarations' => ['fill' => 'plain'], 'order' => 0],
            ['selector' => new CssSelector('rect', ['c'], null), 'declarations' => ['fill' => 'compound'], 'order' => 1],
        ]);
        self::assertSame(['fill' => 'compound'], $sheet->declarationsFor('rect', ['c'], null));
    }
}
