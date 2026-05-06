<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Document\Encryption;
use DragonOfMercy\PhpPdf\Document\Metadata;
use DragonOfMercy\PhpPdf\Exception\PdfException;
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

    public function testEncryptionReturnsSameInstanceAcrossCalls(): void
    {
        $doc = new Document();
        self::assertSame($doc->encryption(), $doc->encryption());
    }

    public function testEncryptionDefaultsToReservedBits(): void
    {
        $e = (new Document())->encryption();
        self::assertNull($e->userPassword);
        self::assertSame(0xFFFFF0C0, $e->permissions);
        self::assertFalse($e->encryptMetadata);
    }

    public function testEncryptedOutputContainsEncryptReferenceInTrailer(): void
    {
        $doc = new Document();
        $doc->metadata()
            ->title('Secret')
            ->creationDate(new \DateTimeImmutable('2026-01-01T12:00:00Z'))
            ->documentId('abcdef0123456789abcdef0123456789');
        $doc->encryption()
            ->userPassword('user')
            ->ownerPassword('owner')
            ->allowPrint()
            ->withRandomSource(fn (int $n) => str_repeat("\x00", $n));
        $doc->addPage();
        $bytes = $doc->output();

        self::assertStringContainsString('/Encrypt 5 0 R', $bytes);
        self::assertStringContainsString('/ID [<ABCDEF', $bytes);
    }

    public function testEncryptedOutputWithoutMetadataStillEmitsEncrypt(): void
    {
        $doc = new Document();
        $doc->encryption()
            ->userPassword('user')
            ->ownerPassword('owner')
            ->withRandomSource(fn (int $n) => str_repeat("\x00", $n));
        $doc->addPage();
        $bytes = $doc->output();

        self::assertStringContainsString('/Encrypt 3 0 R', $bytes);
        self::assertStringContainsString('/ID [<', $bytes);
        self::assertStringNotContainsString('/Info', $bytes);
    }

    public function testEncryptionRequiresUserAndOwnerPasswords(): void
    {
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $this->expectExceptionMessage('user password');
        $doc = new Document();
        $doc->encryption()->ownerPassword('owner');
        $doc->addPage();
        $doc->output();
    }

    public function testAddPageReturnsPageInstance(): void
    {
        $doc = new Document();
        self::assertInstanceOf(\DragonOfMercy\PhpPdf\Page::class, $doc->addPage());
    }

    public function testPageWithoutDrawingDoesNotEmitContentsEntry(): void
    {
        $doc = new Document();
        $doc->addPage();
        $bytes = $doc->output();
        self::assertStringNotContainsString('/Contents', $bytes);
    }

    public function testPageWithDrawingEmitsContentsReference(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->rect(10, 10, 100, 50)->stroke();
        $bytes = $doc->output();
        self::assertStringContainsString('/Contents 4 0 R', $bytes);
        self::assertStringContainsString('/Filter /FlateDecode', $bytes);
    }

    public function testFontResourcesEmittedWhenPageUsesText(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->setFont(\DragonOfMercy\PhpPdf\Font::helvetica()->bold(), 14);
        $page->text(50, 50, 'Hello');

        $bytes = $doc->output();

        // The page dict contains /Resources with /Font /F1 pointing to an indirect object
        self::assertMatchesRegularExpression(
            '/\/Resources << \/Font << \/F1 \d+ 0 R >> >>/',
            $bytes,
        );

        // A font indirect object exists with /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold
        self::assertStringContainsString('/Type /Font', $bytes);
        self::assertStringContainsString('/Subtype /Type1', $bytes);
        self::assertStringContainsString('/BaseFont /Helvetica-Bold', $bytes);
        self::assertStringContainsString('/Encoding /WinAnsiEncoding', $bytes);
    }

    public function testFontsSharedAcrossPages(): void
    {
        $doc = new Document();
        $p1 = $doc->addPage();
        $p1->setFont(\DragonOfMercy\PhpPdf\Font::helvetica(), 12);
        $p1->text(50, 50, 'A');

        $p2 = $doc->addPage();
        $p2->setFont(\DragonOfMercy\PhpPdf\Font::helvetica(), 18);  // same font, different size
        $p2->text(50, 50, 'B');

        $bytes = $doc->output();

        // Only one /BaseFont /Helvetica entry in the file
        self::assertSame(1, substr_count($bytes, '/BaseFont /Helvetica '));
    }

    public function testPageWithoutTextHasNoResources(): void
    {
        $doc = new Document();
        $doc->addPage();  // no drawing, no text
        $bytes = $doc->output();
        self::assertStringNotContainsString('/Resources', $bytes);
    }

    public function testSetFontWithoutTextEmitsNoFontResources(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->setFont(\DragonOfMercy\PhpPdf\Font::helvetica(), 12);
        // No text() call
        $bytes = $doc->output();

        self::assertStringNotContainsString('/Resources', $bytes);
        self::assertStringNotContainsString('/BaseFont', $bytes);
        self::assertStringNotContainsString('/Type /Font', $bytes);
    }

    public function testMetricsRegistrySharedAcrossPages(): void
    {
        $doc = new Document();
        $page1 = $doc->addPage();
        $page2 = $doc->addPage();
        self::assertSame($page1->metricsRegistry(), $page2->metricsRegistry());
    }
}
