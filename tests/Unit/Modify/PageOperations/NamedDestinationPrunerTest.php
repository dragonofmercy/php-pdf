<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Modify\PageOperations;

use DragonOfMercy\PhpPdf\Modify\PageOperations\NamedDestinationPruner;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Tests\Golden\NamedDestFixtureBuilder;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use PHPUnit\Framework\TestCase;

final class NamedDestinationPrunerTest extends TestCase
{
    public function testRemovesNamedDestTargetingDeletedPage(): void
    {
        $reader = PdfReader::fromBytes(NamedDestFixtureBuilder::build());

        $objects = (new NamedDestinationPruner())->prune($reader, [4]);

        self::assertNotSame([], $objects, 'The /Dests leaf must be re-emitted');
        $leaf = null;
        foreach ($objects as $obj) {
            if ($obj->objectNumber === 7) {
                $leaf = $obj->dictionaryPayload();
            }
        }
        self::assertNotNull($leaf);
        $names = $leaf->get(Name::of('Names'));
        self::assertInstanceOf(PdfArray::class, $names);
        $serialized = $names->toBytes();
        self::assertStringContainsString('dest_p1', $serialized);
        self::assertStringNotContainsString('dest_p2', $serialized);
    }

    public function testNoDeletionsReturnsEmpty(): void
    {
        $reader = PdfReader::fromBytes(NamedDestFixtureBuilder::build());
        self::assertSame([], (new NamedDestinationPruner())->prune($reader, []));
    }
}
