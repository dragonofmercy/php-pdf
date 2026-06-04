<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Table;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Table\ColumnGroup;
use DragonOfMercy\PhpPdf\TextAlign;
use PHPUnit\Framework\TestCase;

final class ColumnGroupTest extends TestCase
{
    public function testOfDefaults(): void
    {
        $g = ColumnGroup::of('Q1', 3);
        self::assertSame('Q1', $g->label);
        self::assertSame(3, $g->span);
        self::assertNull($g->fill);
        self::assertNull($g->textColor);
        self::assertNull($g->bold);
        self::assertSame(TextAlign::CENTER, $g->align);
    }

    public function testSpacerIsEmptyLabel(): void
    {
        $g = ColumnGroup::spacer();
        self::assertSame('', $g->label);
        self::assertSame(1, $g->span);
        self::assertTrue($g->isSpacer());
    }

    public function testStyleOverridesAreImmutable(): void
    {
        $base = ColumnGroup::of('Q1', 2);
        $styled = $base->fill(Color::gray(220))->textColor(Color::rgb(0, 0, 128))->bold()->align(TextAlign::LEFT);
        self::assertNull($base->fill, 'original must be unchanged');
        self::assertEquals(Color::gray(220), $styled->fill);
        self::assertEquals(Color::rgb(0, 0, 128), $styled->textColor);
        self::assertTrue($styled->bold);
        self::assertSame(TextAlign::LEFT, $styled->align);
        self::assertSame(2, $styled->span);
    }

    public function testSpanBelowOneThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Column group span must be >= 1, got 0');
        ColumnGroup::of('Q1', 0);
    }

    public function testSpacerSpanBelowOneThrows(): void
    {
        $this->expectException(PdfException::class);
        ColumnGroup::spacer(0);
    }
}
