<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Modify;

use DragonOfMercy\PhpPdf\Modify\PendingChanges;
use PHPUnit\Framework\TestCase;

final class PendingChangesTest extends TestCase
{
    public function testEmptyByDefault(): void
    {
        self::assertTrue((new PendingChanges())->isEmpty());
    }

    public function testFlattenAllMakesNonEmpty(): void
    {
        $p = new PendingChanges();
        $p->flatten = true;
        $p->flattenNames = null;
        self::assertFalse($p->isEmpty());
    }

    public function testFlattenSubsetMakesNonEmpty(): void
    {
        $p = new PendingChanges();
        $p->flatten = true;
        $p->flattenNames = ['name'];
        self::assertFalse($p->isEmpty());
    }
}
