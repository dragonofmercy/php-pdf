<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\CellResult;
use PHPUnit\Framework\TestCase;

final class CellResultTest extends TestCase
{
    public function testConstructionAndAccessors(): void
    {
        $r = new CellResult(
            x: 250.0,
            y: 75.0,
            height: 25.0,
            lineCount: 2,
            brokenWords: 1,
            textOverflow: false,
            effectiveWidth: 120.0,
        );
        self::assertSame(250.0, $r->x);
        self::assertSame(75.0, $r->y);
        self::assertSame(25.0, $r->height);
        self::assertSame(2, $r->lineCount);
        self::assertSame(1, $r->brokenWords);
        self::assertFalse($r->textOverflow);
        self::assertSame(120.0, $r->effectiveWidth);
    }

    public function testZeroLineCountForEmptyText(): void
    {
        $r = new CellResult(
            x: 100.0,
            y: 60.0,
            height: 10.0,
            lineCount: 0,
            brokenWords: 0,
            textOverflow: false,
            effectiveWidth: 0.0,
        );
        self::assertSame(0, $r->lineCount);
    }

    public function testTextOverflowFlag(): void
    {
        $r = new CellResult(x: 0, y: 0, height: 0, lineCount: 1, brokenWords: 0, textOverflow: true, effectiveWidth: 0.0);
        self::assertTrue($r->textOverflow);
    }
}
