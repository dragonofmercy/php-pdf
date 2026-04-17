<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit;

use PhpPdf\Document;
use PhpPdf\Document\Metadata;
use PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class DocumentTest extends TestCase
{
    public function testOutputStartsWithPdfHeader(): void
    {
        $doc = new Document();
        $doc->addPage();
        self::assertStringStartsWith("%PDF-1.7\n", $doc->output());
    }

    public function testOutputWithoutPagesThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Document has no pages');
        (new Document())->output();
    }

    public function testSaveWritesFile(): void
    {
        $doc = new Document();
        $doc->addPage();
        $path = tempnam(sys_get_temp_dir(), 'phppdf_');
        self::assertIsString($path);
        try {
            $doc->save($path);
            $content = file_get_contents($path);
            self::assertIsString($content);
            self::assertStringStartsWith("%PDF-1.7\n", $content);
            self::assertStringEndsWith("%%EOF\n", $content);
        } finally {
            unlink($path);
        }
    }

    public function testSaveOnUnwritablePathThrows(): void
    {
        $this->expectException(PdfException::class);
        $doc = new Document();
        $doc->addPage();
        $doc->save('/nonexistent_dir_phppdf/out.pdf');
    }

    public function testMetadataReturnsSameInstanceAcrossCalls(): void
    {
        $doc = new Document();
        self::assertSame($doc->metadata(), $doc->metadata());
    }

    public function testMetadataDefaultsAreAllNull(): void
    {
        $m = (new Document())->metadata();
        self::assertNull($m->title);
        self::assertNull($m->author);
        self::assertNull($m->creationDate);
        self::assertNull($m->documentId);
    }

    public function testOutputWithMetadataEmitsInfoReferenceInTrailer(): void
    {
        $doc = new Document();
        $doc->metadata()
            ->title('Test')
            ->author('User')
            ->creationDate(new \DateTimeImmutable('2026-01-01T12:00:00Z'))
            ->documentId('abcdef0123456789abcdef0123456789');
        $doc->addPage();
        $bytes = $doc->output();

        // /Info 3 0 R appears in the trailer dict
        self::assertMatchesRegularExpression('/trailer\n<< .*\/Info 3 0 R/', $bytes);
    }

    public function testOutputWithMetadataEmitsIdInTrailer(): void
    {
        $doc = new Document();
        $doc->metadata()
            ->title('Test')
            ->creationDate(new \DateTimeImmutable('2026-01-01T12:00:00Z'))
            ->documentId('abcdef0123456789abcdef0123456789');
        $doc->addPage();
        $bytes = $doc->output();

        self::assertStringContainsString(
            '/ID [<ABCDEF0123456789ABCDEF0123456789> <ABCDEF0123456789ABCDEF0123456789>]',
            $bytes,
        );
    }

    public function testOutputWithMetadataEmitsMetadataReferenceInCatalog(): void
    {
        $doc = new Document();
        $doc->metadata()->title('X')
            ->creationDate(new \DateTimeImmutable('2026-01-01T12:00:00Z'));
        $doc->addPage();
        $bytes = $doc->output();

        // Catalog (object 1) has /Metadata 4 0 R
        self::assertMatchesRegularExpression(
            '/1 0 obj\n<< [^>]*\/Metadata 4 0 R[^>]* >>\nendobj/',
            $bytes,
        );
    }

    public function testOutputWithoutMetadataIsByteIdenticalToPhase0(): void
    {
        // Regression check: no metadata usage -> same output as Phase 0 fixture
        $doc = new Document();
        $doc->addPage();
        $bytes = $doc->output();

        $fixture = file_get_contents(__DIR__ . '/../Golden/fixtures/empty-page.pdf');
        self::assertIsString($fixture);
        self::assertSame($fixture, $bytes);
    }

    public function testDefaultProducerIsSetWhenMetadataUsed(): void
    {
        $doc = new Document();
        $doc->metadata()
            ->title('T')
            ->creationDate(new \DateTimeImmutable('2026-01-01T12:00:00Z'));
        $doc->addPage();
        $bytes = $doc->output();

        // Producer default -> TextString UTF-16BE hex starting with <FEFF
        self::assertStringContainsString('/Producer <FEFF', $bytes);
    }
}
