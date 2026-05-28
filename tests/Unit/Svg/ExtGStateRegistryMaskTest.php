<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\ExtGStateRegistry;
use PHPUnit\Framework\TestCase;

final class ExtGStateRegistryMaskTest extends TestCase
{
    public function testNameForWithMaskAllocates(): void
    {
        $reg = new ExtGStateRegistry();
        $name = $reg->nameForWithMask(1.0, 1.0, 0);
        self::assertSame('Gs0', $name);
        $entries = $reg->entries();
        self::assertArrayHasKey('Gs0', $entries);
        self::assertSame(1.0, $entries['Gs0']['ca']);
        self::assertSame(1.0, $entries['Gs0']['CA']);
        self::assertSame(0, $entries['Gs0']['smaskEmbeddedIndex']);
    }

    public function testNameForWithMaskDoesNotEliminateOpacityNoop(): void
    {
        // Even at fully-opaque, an entry with a mask must NOT be eliminated to
        // empty (it carries the smask reference, which is the whole point).
        $reg = new ExtGStateRegistry();
        $name = $reg->nameForWithMask(1.0, 1.0, 3);
        self::assertNotSame('', $name);
    }

    public function testNameForOpacityOnlyKeepsLegacyShape(): void
    {
        $reg = new ExtGStateRegistry();
        $name = $reg->nameFor(0.5, 0.5);
        self::assertSame('Gs0', $name);
        $entries = $reg->entries();
        self::assertSame(0.5, $entries['Gs0']['ca']);
        self::assertSame(0.5, $entries['Gs0']['CA']);
        // Per the schema extension, smaskEmbeddedIndex is present and null.
        self::assertNull($entries['Gs0']['smaskEmbeddedIndex']);
    }

    public function testFullyOpaqueWithoutMaskRemainsNoop(): void
    {
        $reg = new ExtGStateRegistry();
        self::assertSame('', $reg->nameFor(1.0, 1.0));
    }

    public function testDeduplicationByKey(): void
    {
        $reg = new ExtGStateRegistry();
        $a = $reg->nameForWithMask(0.5, 0.5, 7);
        $b = $reg->nameForWithMask(0.5, 0.5, 7);
        self::assertSame($a, $b);
        self::assertCount(1, $reg->entries());
    }

    public function testDifferentMaskIndexProducesDistinctEntry(): void
    {
        $reg = new ExtGStateRegistry();
        $a = $reg->nameForWithMask(0.5, 0.5, 7);
        $b = $reg->nameForWithMask(0.5, 0.5, 8);
        self::assertNotSame($a, $b);
        self::assertCount(2, $reg->entries());
    }

    public function testOpacityOnlyAndMaskedAreDistinct(): void
    {
        $reg = new ExtGStateRegistry();
        $opacity = $reg->nameFor(0.5, 0.5);
        $masked = $reg->nameForWithMask(0.5, 0.5, 0);
        self::assertNotSame($opacity, $masked);
    }
}
