<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\PageMargins;
use PHPUnit\Framework\TestCase;

final class PageMarginsTest extends TestCase
{
    public function testConstructorStoresFourSides(): void
    {
        $m = new PageMargins(top: 10.0, right: 15.0, bottom: 20.0, left: 25.0);
        self::assertSame(10.0, $m->top);
        self::assertSame(15.0, $m->right);
        self::assertSame(20.0, $m->bottom);
        self::assertSame(25.0, $m->left);
    }

    public function testAllFactoryProducesEqualSides(): void
    {
        $m = PageMargins::all(12.5);
        self::assertSame(12.5, $m->top);
        self::assertSame(12.5, $m->right);
        self::assertSame(12.5, $m->bottom);
        self::assertSame(12.5, $m->left);
    }

    public function testSymmetricFactoryMirrorsVerticalAndHorizontal(): void
    {
        $m = PageMargins::symmetric(vertical: 20.0, horizontal: 15.0);
        self::assertSame(20.0, $m->top);
        self::assertSame(15.0, $m->right);
        self::assertSame(20.0, $m->bottom);
        self::assertSame(15.0, $m->left);
    }

    public function testSidesFactoryDefaultsOmittedSidesToZero(): void
    {
        $m = PageMargins::sides(top: 10.0, left: 5.0);
        self::assertSame(10.0, $m->top);
        self::assertSame(0.0, $m->right);
        self::assertSame(0.0, $m->bottom);
        self::assertSame(5.0, $m->left);
    }

    public function testConstructorThrowsOnNegativeTop(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Page margin top cannot be negative, got -1');
        new PageMargins(top: -1.0, right: 0.0, bottom: 0.0, left: 0.0);
    }

    public function testConstructorThrowsOnNegativeRight(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Page margin right cannot be negative');
        new PageMargins(top: 0.0, right: -1.0, bottom: 0.0, left: 0.0);
    }

    public function testConstructorThrowsOnNegativeBottom(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Page margin bottom cannot be negative');
        new PageMargins(top: 0.0, right: 0.0, bottom: -1.0, left: 0.0);
    }

    public function testConstructorThrowsOnNegativeLeft(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Page margin left cannot be negative');
        new PageMargins(top: 0.0, right: 0.0, bottom: 0.0, left: -1.0);
    }

    public function testZeroValuesAreAccepted(): void
    {
        $m = PageMargins::all(0.0);
        self::assertSame(0.0, $m->top);
    }

    public function testIsZeroIsTrueWhenAllSidesAreZero(): void
    {
        self::assertTrue(PageMargins::all(0.0)->isZero());
    }

    public function testIsZeroIsFalseWhenAnySideIsNonZero(): void
    {
        self::assertFalse(PageMargins::sides(top: 1.0)->isZero());
        self::assertFalse(PageMargins::sides(right: 1.0)->isZero());
        self::assertFalse(PageMargins::sides(bottom: 1.0)->isZero());
        self::assertFalse(PageMargins::sides(left: 1.0)->isZero());
    }
}
