<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\PdfA;

use DragonOfMercy\PhpPdf\PdfA\AFRelationship;
use DragonOfMercy\PhpPdf\PdfA\AttachedFile;
use DragonOfMercy\PhpPdf\PdfA\EmbeddedFileEmitter;
use PHPUnit\Framework\TestCase;

final class EmbeddedFileEmitterTest extends TestCase
{
    public function testEmitsFilespecAndStream(): void
    {
        $a = new AttachedFile('factur-x.xml', '<invoice/>', AFRelationship::Data, 'text/xml', 'Invoice data', new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $result = (new EmbeddedFileEmitter())->emit([$a], firstObjectNumber: 20);

        self::assertCount(2, $result['objects']);
        self::assertSame([20], array_map(static fn ($r) => $r->objectNumber, $result['filespecRefs']));

        $filespec = $result['objects'][0]->toBytes();
        self::assertStringContainsString('/Type /Filespec', $filespec);
        self::assertStringContainsString('/F (factur-x.xml)', $filespec);
        self::assertStringContainsString('/AFRelationship /Data', $filespec);
        self::assertStringContainsString('/Desc (Invoice data)', $filespec);
        self::assertStringContainsString('/EF', $filespec);
        self::assertStringContainsString('21 0 R', $filespec);

        $stream = $result['objects'][1]->toBytes();
        self::assertStringContainsString('/Type /EmbeddedFile', $stream);
        self::assertStringContainsString('/Subtype /text#2Fxml', $stream);
        self::assertStringContainsString('/Params', $stream);
        self::assertStringContainsString('/Size 10', $stream);
        self::assertStringContainsString('/ModDate (D:20260101000000Z)', $stream);
        self::assertStringContainsString('<invoice/>', $stream);
    }

    public function testOmitsDescWhenNull(): void
    {
        $a = new AttachedFile('a.bin', 'xx', AFRelationship::Source, 'application/octet-stream', null, new \DateTimeImmutable('@0'));
        $result = (new EmbeddedFileEmitter())->emit([$a], firstObjectNumber: 5);
        self::assertStringNotContainsString('/Desc', $result['objects'][0]->toBytes());
        self::assertSame([5], array_map(static fn ($r) => $r->objectNumber, $result['filespecRefs']));
    }
}
