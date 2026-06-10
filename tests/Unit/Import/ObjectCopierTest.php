<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Import;

use DragonOfMercy\PhpPdf\Import\ObjectCopier;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\PdfObjectAllocator;
use PHPUnit\Framework\TestCase;

final class ObjectCopierTest extends TestCase
{
    /** @param non-empty-array<int, string> $objects */
    private static function buildPdf(array $objects): string
    {
        $body = "%PDF-1.6\n";
        $offsets = [];
        foreach ($objects as $number => $payload) {
            $offsets[$number] = strlen($body);
            $body .= "{$number} 0 obj\n{$payload}\nendobj\n";
        }
        $size = max(array_keys($objects)) + 1;
        $xrefAt = strlen($body);
        $body .= "xref\n0 1\n0000000000 65535 f \n";
        foreach ($offsets as $number => $offset) {
            $body .= "{$number} 1\n" . sprintf("%010d 00000 n \n", $offset);
        }
        $body .= "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xrefAt}\n%%EOF\n";
        return $body;
    }

    private static function reader(): PdfReader
    {
        return PdfReader::fromBytes(self::buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Resources 4 0 R >>',
            4 => '<< /Font << /F1 5 0 R >> /Shared [5 0 R 5 0 R] /Loop 4 0 R >>',
            5 => '<< /Type /Font /Widths 6 0 R >>',
            6 => "<< /Length 2 /Filter /FlateDecode >>\nstream\nAB\nendstream",
        ]));
    }

    public function testScalarsPassThroughUnchanged(): void
    {
        $copier = new ObjectCopier(self::reader(), new PdfObjectAllocator(100));
        self::assertEquals(PdfNumber::ofInt(7), $copier->copy(PdfNumber::ofInt(7)));
        self::assertEquals(Name::of('X'), $copier->copy(Name::of('X')));
        self::assertSame([], $copier->collectedObjects());
    }

    public function testReferencesAreRenumberedAndSubgraphCollected(): void
    {
        $copier = new ObjectCopier(self::reader(), new PdfObjectAllocator(100));
        $copied = $copier->copy(PdfReference::to(4, 0));

        self::assertEquals(PdfReference::to(100, 0), $copied);
        $objects = $copier->collectedObjects();
        // 4 (resources), 5 (font), 6 (widths stream) => three copied objects
        self::assertCount(3, $objects);
        self::assertSame(100, $objects[0]->objectNumber);

        $resources = $objects[0]->payload();
        self::assertInstanceOf(Dictionary::class, $resources);
        $fontDict = $resources->get(Name::of('Font'));
        self::assertInstanceOf(Dictionary::class, $fontDict);
        self::assertEquals(PdfReference::to(101, 0), $fontDict->get(Name::of('F1')));
    }

    public function testSharedReferencesMapToTheSameNewObject(): void
    {
        $copier = new ObjectCopier(self::reader(), new PdfObjectAllocator(100));
        $copied = $copier->copy(PdfReference::to(4, 0));
        self::assertInstanceOf(PdfReference::class, $copied);

        $objects = $copier->collectedObjects();
        $resources = $objects[0]->payload();
        self::assertInstanceOf(Dictionary::class, $resources);
        $shared = $resources->get(Name::of('Shared'));
        self::assertInstanceOf(PdfArray::class, $shared);
        // both array slots point at the SAME renumbered object
        self::assertEquals($shared->elements()[0], $shared->elements()[1]);
        // and only one copy of object 5 was made (3 objects total, not 4)
        self::assertCount(3, $objects);
    }

    public function testSelfReferenceCycleIsPreserved(): void
    {
        $copier = new ObjectCopier(self::reader(), new PdfObjectAllocator(100));
        $copier->copy(PdfReference::to(4, 0));
        $resources = $copier->collectedObjects()[0]->payload();
        self::assertInstanceOf(Dictionary::class, $resources);
        // /Loop 4 0 R must become a reference to the COPY of object 4 itself
        self::assertEquals(PdfReference::to(100, 0), $resources->get(Name::of('Loop')));
    }

    public function testStreamsKeepRawDataAndFilter(): void
    {
        $copier = new ObjectCopier(self::reader(), new PdfObjectAllocator(100));
        $copier->copy(PdfReference::to(6, 0));
        $objects = $copier->collectedObjects();
        self::assertCount(1, $objects);
        $stream = $objects[0]->payload();
        self::assertInstanceOf(ReadStream::class, $stream);
        self::assertSame('AB', $stream->rawData());
        self::assertEquals(Name::of('FlateDecode'), $stream->dict->get(Name::of('Filter')));
    }

    public function testCopyingTwiceReusesTheMap(): void
    {
        $copier = new ObjectCopier(self::reader(), new PdfObjectAllocator(100));
        $first = $copier->copy(PdfReference::to(5, 0));
        $second = $copier->copy(PdfReference::to(5, 0));
        self::assertEquals($first, $second);
        self::assertCount(2, $copier->collectedObjects()); // 5 + 6, once each
    }
}
