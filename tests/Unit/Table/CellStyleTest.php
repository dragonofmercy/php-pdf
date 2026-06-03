<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Table;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\TextAlign;
use DragonOfMercy\PhpPdf\Table\CellStyle;
use PHPUnit\Framework\TestCase;

final class CellStyleTest extends TestCase
{
    public function testEmptyStyleHasAllNull(): void
    {
        $s = CellStyle::new();
        self::assertNull($s->textColor);
        self::assertNull($s->fill);
        self::assertNull($s->bold);
        self::assertNull($s->align);
    }

    public function testWithersAreImmutable(): void
    {
        $base = CellStyle::new();
        $red = $base->withTextColor(Color::rgb(255, 0, 0));
        self::assertNull($base->textColor);
        self::assertEquals(Color::rgb(255, 0, 0), $red->textColor);
        self::assertTrue($base->withBold(true)->bold);
        self::assertSame(TextAlign::RIGHT, $base->withAlign(TextAlign::RIGHT)->align);
        self::assertEquals(Color::gray(240), $base->withFill(Color::gray(240))->fill);
    }
}
