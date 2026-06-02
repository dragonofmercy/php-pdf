<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\PdfA;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\PdfA\AFRelationship;
use DragonOfMercy\PhpPdf\PdfA\AttachedFile;
use PHPUnit\Framework\TestCase;

final class AttachedFileTest extends TestCase
{
    public function testHoldsFields(): void
    {
        $when = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $a = new AttachedFile('factur-x.xml', '<x/>', AFRelationship::Data, 'text/xml', 'Invoice', $when);
        self::assertSame('factur-x.xml', $a->name);
        self::assertSame('<x/>', $a->bytes);
        self::assertSame(AFRelationship::Data, $a->relationship);
        self::assertSame('text/xml', $a->mime);
        self::assertSame('Invoice', $a->description);
        self::assertSame($when, $a->modDate);
    }

    public function testSubtypeNameEscapesSlash(): void
    {
        $a = new AttachedFile('a.xml', 'x', AFRelationship::Data, 'text/xml', null, new \DateTimeImmutable('@0'));
        self::assertSame('text#2Fxml', $a->subtypeName());
    }

    public function testEmptyNameRejected(): void
    {
        $this->expectException(PdfException::class);
        new AttachedFile('', 'x', AFRelationship::Data, 'text/xml', null, new \DateTimeImmutable('@0'));
    }
}
