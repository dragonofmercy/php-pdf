<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\ExtGStateRegistry;
use PHPUnit\Framework\TestCase;

final class ExtGStateRegistryTest extends TestCase
{
    public function testFirstPairGetsGs0(): void
    {
        $reg = new ExtGStateRegistry();
        self::assertSame('Gs0', $reg->nameFor(0.5, 1.0));
    }

    public function testIdenticalPairReturnsSameName(): void
    {
        $reg = new ExtGStateRegistry();
        $a = $reg->nameFor(0.5, 0.8);
        $b = $reg->nameFor(0.5, 0.8);
        self::assertSame($a, $b);
    }

    public function testDifferentPairsGetSequentialNames(): void
    {
        $reg = new ExtGStateRegistry();
        $a = $reg->nameFor(0.5, 1.0);
        $b = $reg->nameFor(0.5, 0.5);
        $c = $reg->nameFor(1.0, 0.5);
        self::assertSame('Gs0', $a);
        self::assertSame('Gs1', $b);
        self::assertSame('Gs2', $c);
    }

    public function testEntriesIncludeAllPairs(): void
    {
        $reg = new ExtGStateRegistry();
        $reg->nameFor(0.5, 1.0);
        $reg->nameFor(0.25, 0.75);
        $entries = $reg->entries();
        self::assertCount(2, $entries);
        self::assertSame(['ca' => 0.5, 'CA' => 1.0, 'smaskEmbeddedIndex' => null], $entries['Gs0']);
        self::assertSame(['ca' => 0.25, 'CA' => 0.75, 'smaskEmbeddedIndex' => null], $entries['Gs1']);
    }

    public function testFullyOpaqueDoesNotRegister(): void
    {
        // Convention: (1.0, 1.0) means no gs needed; name is empty string.
        $reg = new ExtGStateRegistry();
        self::assertSame('', $reg->nameFor(1.0, 1.0));
        self::assertSame([], $reg->entries());
    }
}
