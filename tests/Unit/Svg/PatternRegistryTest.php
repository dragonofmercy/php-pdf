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
}
