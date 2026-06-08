<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Table;

use DragonOfMercy\PhpPdf\Table\Cell;
use DragonOfMercy\PhpPdf\Text\Direction;
use PHPUnit\Framework\TestCase;

final class CellDirectionTest extends TestCase
{
    public function testDirectionDefaultsNull(): void
    {
        self::assertNull(Cell::of('x')->direction);
    }

    public function testDirectionWitherIsImmutable(): void
    {
        $base = Cell::of('x');
        $rtl = $base->direction(Direction::RTL);
        self::assertNull($base->direction);
        self::assertSame(Direction::RTL, $rtl->direction);
        self::assertSame('x', $rtl->text);
    }

    public function testDirectionSurvivesOtherWithers(): void
    {
        $cell = Cell::of('x')->direction(Direction::RTL)->bold();
        self::assertSame(Direction::RTL, $cell->direction);
    }
}
