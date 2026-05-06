<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit;

use PhpPdf\Border;
use PhpPdf\BorderStyle;
use PhpPdf\Color;
use PHPUnit\Framework\TestCase;

final class BorderTest extends TestCase
{
    public function testAllActivatesAllFourSides(): void
    {
        $b = Border::all();
        self::assertTrue($b->top);
        self::assertTrue($b->right);
        self::assertTrue($b->bottom);
        self::assertTrue($b->left);
    }

    public function testAllDefaults(): void
    {
        $b = Border::all();
        self::assertSame(0.5, $b->width);
        self::assertSame(BorderStyle::SOLID, $b->style);
        self::assertSame("0 0 0 rg\n", $b->color->toPdfOperator(stroke: false));
    }

    public function testNoneActivatesNoSides(): void
    {
        $b = Border::none();
        self::assertFalse($b->top);
        self::assertFalse($b->right);
        self::assertFalse($b->bottom);
        self::assertFalse($b->left);
        self::assertTrue($b->isEmpty());
    }

    public function testSidesPartial(): void
    {
        $b = Border::sides(top: true, bottom: true);
        self::assertTrue($b->top);
        self::assertFalse($b->right);
        self::assertTrue($b->bottom);
        self::assertFalse($b->left);
        self::assertFalse($b->isEmpty());
    }

    public function testIsEmptyTrueWhenNoSidesActive(): void
    {
        self::assertTrue(Border::sides()->isEmpty());
        self::assertTrue(Border::none()->isEmpty());
        self::assertFalse(Border::all()->isEmpty());
    }

    public function testWithWidthReturnsNewInstance(): void
    {
        $a = Border::all();
        $b = $a->withWidth(2.5);
        self::assertNotSame($a, $b);
        self::assertSame(0.5, $a->width);
        self::assertSame(2.5, $b->width);
    }

    public function testWithColorReturnsNewInstance(): void
    {
        $a = Border::all();
        $red = Color::rgb(255, 0, 0);
        $b = $a->withColor($red);
        self::assertNotSame($a, $b);
        self::assertSame($red, $b->color);
    }

    public function testWithStyleReturnsNewInstance(): void
    {
        $a = Border::all();
        $b = $a->withStyle(BorderStyle::DASHED);
        self::assertNotSame($a, $b);
        self::assertSame(BorderStyle::SOLID, $a->style);
        self::assertSame(BorderStyle::DASHED, $b->style);
    }

    public function testFluentChain(): void
    {
        $b = Border::sides(top: true, left: true)
            ->withWidth(1.5)
            ->withColor(Color::rgb(255, 0, 0))
            ->withStyle(BorderStyle::DOTTED);
        self::assertTrue($b->top);
        self::assertFalse($b->right);
        self::assertFalse($b->bottom);
        self::assertTrue($b->left);
        self::assertSame(1.5, $b->width);
        self::assertSame(BorderStyle::DOTTED, $b->style);
    }

    public function testSidesPreservesWidthAndColorWhenChained(): void
    {
        $b = Border::sides(top: true)->withWidth(3.0);
        // Side flags untouched after withWidth.
        self::assertTrue($b->top);
        self::assertFalse($b->right);
        self::assertSame(3.0, $b->width);
    }
}
