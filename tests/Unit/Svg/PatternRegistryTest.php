<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\PatternRegistry;
use PHPUnit\Framework\TestCase;

final class PatternRegistryTest extends TestCase
{
    public function testDedupAndNaming(): void
    {
        $r = new PatternRegistry();
        self::assertSame('P0', $r->nameFor('<< dict A >>'));
        self::assertSame('P1', $r->nameFor('<< dict B >>'));
        self::assertSame('P0', $r->nameFor('<< dict A >>'));
        self::assertSame(['P0' => '<< dict A >>', 'P1' => '<< dict B >>'], $r->entries());
    }

    public function testTilingEntryStoresRefMode(): void
    {
        $r = new \DragonOfMercy\PhpPdf\Svg\PatternRegistry();
        $name = $r->nameForTiling(7); // arbitrary embedded-pattern index
        self::assertSame('P0', $name);
        $entries = $r->refEntries();
        self::assertSame([0 => ['name' => 'P0', 'embeddedIndex' => 7]], $entries);
    }

    public function testInlineAndTilingShareNameNamespace(): void
    {
        $r = new \DragonOfMercy\PhpPdf\Svg\PatternRegistry();
        self::assertSame('P0', $r->nameFor('<< /Type /Pattern /PatternType 2 >>'));
        self::assertSame('P1', $r->nameForTiling(0));
        self::assertSame('P2', $r->nameFor('<< /Type /Pattern /PatternType 2 /Other true >>'));
        self::assertCount(2, $r->entries()); // inline only
        self::assertCount(1, $r->refEntries()); // tiling only
    }

    public function testRefEntriesEmptyByDefault(): void
    {
        $r = new \DragonOfMercy\PhpPdf\Svg\PatternRegistry();
        self::assertSame([], $r->refEntries());
    }
}
