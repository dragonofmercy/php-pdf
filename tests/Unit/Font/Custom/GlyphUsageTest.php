<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Font\Custom\GlyphUsage;
use PHPUnit\Framework\TestCase;

final class GlyphUsageTest extends TestCase
{
    public function testRecordsGidsBucketedByUsageKey(): void
    {
        $usage = new GlyphUsage();
        $usage->record('FS:FreeSans', 36);
        $usage->record('FS:FreeSans', 36);
        $usage->record('FS:FreeSans', 0);
        $usage->record('FB:FreeSansBold', 99);

        self::assertSame([36 => true, 0 => true], $usage->usedGids('FS:FreeSans'));
        self::assertSame([99 => true], $usage->usedGids('FB:FreeSansBold'));
    }

    public function testUnknownKeyReturnsEmptyArray(): void
    {
        self::assertSame([], (new GlyphUsage())->usedGids('never:seen'));
    }
}
