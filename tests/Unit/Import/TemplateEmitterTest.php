<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Import;

use DragonOfMercy\PhpPdf\Import\ImportedPdf;
use DragonOfMercy\PhpPdf\Import\TemplateEmitter;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\PdfObjectAllocator;
use PHPUnit\Framework\TestCase;

final class TemplateEmitterTest extends TestCase
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

    /**
     * @param non-empty-array<int, string> $objects
     * @return array{xobject: \DragonOfMercy\PhpPdf\Writer\Object\IndirectObject, objects: list<\DragonOfMercy\PhpPdf\Writer\Object\IndirectObject>}
     */
    private static function emit(array $objects, int $pageNo = 1, int $firstNumber = 50): array
    {
        $source = new ImportedPdf(PdfReader::fromBytes(self::buildPdf($objects)));
        $template = $source->page($pageNo);
        $allocator = new PdfObjectAllocator($firstNumber);
        return (new TemplateEmitter())->emit($template, $allocator);
    }

    /** @return non-empty-array<int, string> */
    private static function basePage(string $pageExtras = '', string $contents5 = "<< /Length 3 >>\nstream\nq Q\nendstream"): array
    {
        return [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 100] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 5 0 R /Resources << /Font << /F1 6 0 R >> >> ' . $pageExtras . ' >>',
            5 => $contents5,
            6 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
    }

    public function testEmitsFormXObjectWithCopiedResources(): void
    {
        ['xobject' => $xobject, 'objects' => $objects] = self::emit(self::basePage());

        self::assertSame(50, $xobject->objectNumber);
        $payload = $xobject->payload();
        self::assertInstanceOf(ReadStream::class, $payload);
        $dict = $payload->dict;
        self::assertEquals(Name::of('XObject'), $dict->get(Name::of('Type')));
        self::assertEquals(Name::of('Form'), $dict->get(Name::of('Subtype')));
        self::assertEquals(PdfNumber::ofInt(1), $dict->get(Name::of('FormType')));
        self::assertSame('q Q', $payload->rawData());

        $bbox = $dict->get(Name::of('BBox'));
        self::assertInstanceOf(PdfArray::class, $bbox);
        self::assertSame('[0 0 200 100]', $bbox->toBytes());
        $matrix = $dict->get(Name::of('Matrix'));
        self::assertInstanceOf(PdfArray::class, $matrix);
        self::assertSame('[1 0 0 1 0 0]', $matrix->toBytes());

        // resources were copied: a /Resources dict referencing the copied font
        $resources = $dict->get(Name::of('Resources'));
        self::assertInstanceOf(Dictionary::class, $resources);
        // copied subgraph: the font object (and nothing else) follows the XObject
        self::assertCount(1, $objects);
        self::assertSame(51, $objects[0]->objectNumber);
    }

    public function testBoxOriginIsNormalizedThroughTheMatrix(): void
    {
        $objects = self::basePage();
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [10 20 210 120] >>';
        ['xobject' => $xobject] = self::emit($objects);
        $payload = $xobject->payload();
        self::assertInstanceOf(ReadStream::class, $payload);
        self::assertSame('[1 0 0 1 -10 -20]', $payload->dict->get(Name::of('Matrix'))?->toBytes());
        self::assertSame('[10 20 210 120]', $payload->dict->get(Name::of('BBox'))?->toBytes());
    }

    public function testRotate90BakesRotationIntoTheMatrix(): void
    {
        $objects = self::basePage('/Rotate 90');
        ['xobject' => $xobject] = self::emit($objects);
        $payload = $xobject->payload();
        self::assertInstanceOf(ReadStream::class, $payload);
        // box [0 0 200 100]: (x,y) -> (y - 0, 200 - x) => [0 -1 1 0 0 200]
        self::assertSame('[0 -1 1 0 0 200]', $payload->dict->get(Name::of('Matrix'))?->toBytes());
    }

    public function testRotate180And270Matrices(): void
    {
        $payload180 = self::emit(self::basePage('/Rotate 180'))['xobject']->payload();
        self::assertInstanceOf(ReadStream::class, $payload180);
        self::assertSame('[-1 0 0 -1 200 100]', $payload180->dict->get(Name::of('Matrix'))?->toBytes());

        $payload270 = self::emit(self::basePage('/Rotate 270'))['xobject']->payload();
        self::assertInstanceOf(ReadStream::class, $payload270);
        // (x,y) -> (100 - y, x - 0) => [0 1 -1 0 100 0]
        self::assertSame('[0 1 -1 0 100 0]', $payload270->dict->get(Name::of('Matrix'))?->toBytes());
    }

    public function testSingleFlateContentStreamIsCopiedRaw(): void
    {
        $compressed = gzcompress('0 0 m 10 10 l S', 9);
        self::assertIsString($compressed);
        $contents = '<< /Length ' . strlen($compressed) . " /Filter /FlateDecode >>\nstream\n" . $compressed . "\nendstream";
        ['xobject' => $xobject] = self::emit(self::basePage(contents5: $contents));
        $payload = $xobject->payload();
        self::assertInstanceOf(ReadStream::class, $payload);
        self::assertSame($compressed, $payload->rawData());                       // raw bytes untouched
        self::assertEquals(Name::of('FlateDecode'), $payload->dict->get(Name::of('Filter')));
    }

    public function testMultipleContentStreamsAreJoinedAndRecompressed(): void
    {
        $objects = self::basePage();
        $objects[3] = '<< /Type /Page /Parent 2 0 R /Contents [5 0 R 7 0 R] /Resources << >> >>';
        $objects[7] = "<< /Length 3 >>\nstream\nW n\nendstream";
        ['xobject' => $xobject] = self::emit($objects);
        $payload = $xobject->payload();
        self::assertInstanceOf(ReadStream::class, $payload);
        self::assertEquals(Name::of('FlateDecode'), $payload->dict->get(Name::of('Filter')));
        self::assertSame("q Q\nW n", gzuncompress($payload->rawData()));
    }

    public function testGroupEntryIsCopiedThrough(): void
    {
        $objects = self::basePage('/Group << /S /Transparency /CS /DeviceRGB >>');
        ['xobject' => $xobject] = self::emit($objects);
        $payload = $xobject->payload();
        self::assertInstanceOf(ReadStream::class, $payload);
        self::assertInstanceOf(Dictionary::class, $payload->dict->get(Name::of('Group')));
    }

    public function testPageWithoutContentsYieldsEmptyPayload(): void
    {
        $objects = self::basePage();
        $objects[3] = '<< /Type /Page /Parent 2 0 R /Resources << >> >>';
        ['xobject' => $xobject] = self::emit($objects);
        $payload = $xobject->payload();
        self::assertInstanceOf(ReadStream::class, $payload);
        self::assertSame('', $payload->rawData());
        self::assertNull($payload->dict->get(Name::of('Filter')));
    }
}
