<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\PdfA;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\PdfA\AFRelationship;
use DragonOfMercy\PhpPdf\PdfA\PdfALevel;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class PdfA4OutputTest extends TestCase
{
    private static function baseDoc(): Document
    {
        $doc = new Document(Unit::PT);
        $doc->metadata()
            ->title('A4')
            ->creationDate(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'))
            ->documentId('0123456789abcdef0123456789abcdef');
        $doc->addPage();
        return $doc;
    }

    public function testA4UsesPdf20Header(): void
    {
        $doc = self::baseDoc();
        $doc->enablePdfA(PdfALevel::A4);
        $bytes = $doc->output();
        self::assertStringStartsWith("%PDF-2.0", $bytes);
        self::assertStringContainsString('<pdfaid:part>4</pdfaid:part>', $bytes);
        self::assertStringContainsString('<pdfaid:rev>2020</pdfaid:rev>', $bytes);
    }

    public function testA2bKeeps17Header(): void
    {
        $doc = self::baseDoc();
        $doc->enablePdfA(PdfALevel::A2B);
        self::assertStringStartsWith("%PDF-1.7", $doc->output());
    }

    public function testPlainDocumentKeeps17Header(): void
    {
        self::assertStringStartsWith("%PDF-1.7", self::baseDoc()->output());
    }

    public function testA4BaseRejectsAttachments(): void
    {
        $doc = self::baseDoc();
        $doc->attachFile('data', 'x.txt', AFRelationship::Data, 'text/plain', 'desc');
        $doc->enablePdfA(PdfALevel::A4);
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('forbids embedded files');
        $doc->output();
    }

    public function testA4fAllowsAttachments(): void
    {
        $doc = self::baseDoc();
        $doc->attachFile('data', 'x.txt', AFRelationship::Data, 'text/plain', 'desc');
        $doc->enablePdfA(PdfALevel::A4F);
        self::assertStringStartsWith("%PDF-2.0", $doc->output());
    }
}
