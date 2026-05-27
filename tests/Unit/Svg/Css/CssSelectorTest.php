<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Css;

use DragonOfMercy\PhpPdf\Svg\Css\CssSelector;
use PHPUnit\Framework\TestCase;

final class CssSelectorTest extends TestCase
{
    public function testTypeSelectorMatchesTagOnly(): void
    {
        $sel = new CssSelector('rect', [], null);
        self::assertTrue($sel->matches('rect', [], null));
        self::assertFalse($sel->matches('circle', [], null));
    }

    public function testUniversalSelectorMatchesEverything(): void
    {
        $sel = new CssSelector(null, [], null);
        self::assertTrue($sel->matches('rect', [], null));
        self::assertTrue($sel->matches('text', ['a'], 'x'));
    }

    public function testClassSelectorRequiresAllClasses(): void
    {
        $sel = new CssSelector(null, ['a', 'b'], null);
        self::assertTrue($sel->matches('rect', ['a', 'b', 'c'], null));
        self::assertFalse($sel->matches('rect', ['a'], null));
    }

    public function testIdSelectorMatchesId(): void
    {
        $sel = new CssSelector(null, [], 'foo');
        self::assertTrue($sel->matches('rect', [], 'foo'));
        self::assertFalse($sel->matches('rect', [], 'bar'));
        self::assertFalse($sel->matches('rect', [], null));
    }

    public function testCompoundSelectorMatchesTagAndClass(): void
    {
        $sel = new CssSelector('rect', ['hl'], null);
        self::assertTrue($sel->matches('rect', ['hl'], null));
        self::assertFalse($sel->matches('circle', ['hl'], null));
        self::assertFalse($sel->matches('rect', [], null));
    }

    public function testSpecificity(): void
    {
        self::assertSame([0, 0, 0], (new CssSelector(null, [], null))->specificity());
        self::assertSame([0, 0, 1], (new CssSelector('rect', [], null))->specificity());
        self::assertSame([0, 1, 1], (new CssSelector('rect', ['a'], null))->specificity());
        self::assertSame([0, 2, 0], (new CssSelector(null, ['a', 'b'], null))->specificity());
        self::assertSame([1, 0, 0], (new CssSelector(null, [], 'x'))->specificity());
        self::assertSame([1, 1, 1], (new CssSelector('rect', ['a'], 'x'))->specificity());
    }
}
