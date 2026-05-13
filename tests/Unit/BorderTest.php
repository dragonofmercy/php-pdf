<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\BorderStyle;
use DragonOfMercy\PhpPdf\Color;
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
        self::assertNull($b->width);
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
        self::assertNull($a->width);
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

    public function testFactoriesProduceNullWidth(): void
    {
        self::assertNull(Border::all()->width);
        self::assertNull(Border::none()->width);
        self::assertNull(Border::sides(top: true)->width);
    }

    public function testWithColorPreservesNullWidth(): void
    {
        $b = Border::all()->withColor(Color::rgb(1, 2, 3));
        self::assertNull($b->width);
    }

    public function testWithStylePreservesNullWidth(): void
    {
        $b = Border::all()->withStyle(BorderStyle::DASHED);
        self::assertNull($b->width);
    }

    public function testDirectConstructionWithNullWidth(): void
    {
        $b = new Border(
            top: true,
            right: false,
            bottom: false,
            left: false,
            width: null,
            color: Color::rgb(0, 0, 0),
            style: BorderStyle::SOLID,
        );
        self::assertNull($b->width);
    }
}
