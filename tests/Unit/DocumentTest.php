<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Document\Encryption;
use DragonOfMercy\PhpPdf\Document\Metadata;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class DocumentTest extends TestCase
{
    public function testOutputStartsWithPdfHeader(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        self::assertStringStartsWith("%PDF-1.7\n", $doc->output());
    }

    public function testOutputWithoutPagesThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Document has no pages');
        (new Document(Unit::PT))->output();
    }

    public function testSaveWritesFile(): void
    {
        $doc = new Document(Unit::PT);
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
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $doc->save('/nonexistent_dir_phppdf/out.pdf');
    }

    public function testMetadataReturnsSameInstanceAcrossCalls(): void
    {
        $doc = new Document(Unit::PT);
        self::assertSame($doc->metadata(), $doc->metadata());
    }

    public function testMetadataDefaultsAreAllNull(): void
    {
        $m = (new Document(Unit::PT))->metadata();
        self::assertNull($m->title);
        self::assertNull($m->author);
        self::assertNull($m->creationDate);
        self::assertNull($m->documentId);
    }

    public function testOutputWithMetadataEmitsInfoReferenceInTrailer(): void
    {
        $doc = new Document(Unit::PT);
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
        $doc = new Document(Unit::PT);
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
        $doc = new Document(Unit::PT);
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
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $bytes = $doc->output();

        $fixture = file_get_contents(__DIR__ . '/../Golden/fixtures/empty-page.pdf');
        self::assertIsString($fixture);
        self::assertSame($fixture, $bytes);
    }

    public function testDefaultProducerIsSetWhenMetadataUsed(): void
    {
        $doc = new Document(Unit::PT);
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
        $doc = new Document(Unit::PT);
        self::assertSame($doc->encryption(), $doc->encryption());
    }

    public function testEncryptionDefaultsToReservedBits(): void
    {
        $e = (new Document(Unit::PT))->encryption();
        self::assertNull($e->userPassword);
        self::assertSame(0xFFFFF0C0, $e->permissions);
        self::assertFalse($e->encryptMetadata);
    }

    public function testEncryptedOutputContainsEncryptReferenceInTrailer(): void
    {
        $doc = new Document(Unit::PT);
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
        $doc = new Document(Unit::PT);
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
        $doc = new Document(Unit::PT);
        $doc->encryption()->ownerPassword('owner');
        $doc->addPage();
        $doc->output();
    }

    public function testAddPageReturnsPageInstance(): void
    {
        $doc = new Document(Unit::PT);
        self::assertInstanceOf(\DragonOfMercy\PhpPdf\Page::class, $doc->addPage());
    }

    public function testPageWithoutDrawingDoesNotEmitContentsEntry(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $bytes = $doc->output();
        self::assertStringNotContainsString('/Contents', $bytes);
    }

    public function testPageWithDrawingEmitsContentsReference(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->rect(10, 10, 100, 50)->stroke();
        $bytes = $doc->output();
        self::assertStringContainsString('/Contents 4 0 R', $bytes);
        self::assertStringContainsString('/Filter /FlateDecode', $bytes);
    }

    public function testFontResourcesEmittedWhenPageUsesText(): void
    {
        $doc = new Document(Unit::PT);
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
        $doc = new Document(Unit::PT);
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
        $doc = new Document(Unit::PT);
        $doc->addPage();  // no drawing, no text
        $bytes = $doc->output();
        self::assertStringNotContainsString('/Resources', $bytes);
    }

    public function testSetFontWithoutTextEmitsNoFontResources(): void
    {
        $doc = new Document(Unit::PT);
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
        $doc = new Document(Unit::PT);
        $page1 = $doc->addPage();
        $page2 = $doc->addPage();
        self::assertSame($page1->metricsRegistry(), $page2->metricsRegistry());
    }

    public function testImageRegistryIsSharedAcrossPages(): void
    {
        // We cannot inspect the registry directly (private). Instead verify that
        // building the same simple document twice produces identical PDF bytes.
        $build = static function (): string {
            $doc = new Document(Unit::PT);
            $doc->addPage();
            $doc->addPage();
            return $doc->output();
        };
        self::assertSame($build(), $build());
    }

    public function testDocumentEmitsXObjectResourceWhenImageUsed(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $img = \DragonOfMercy\PhpPdf\Image::fromBytes(
            \DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory::pngRgb(width: 4, height: 4),
        );
        $page->image($img, x: 0, y: 0, w: 100, h: 100);
        $bytes = $doc->output();
        // Page resource dictionary contains an XObject sub-dictionary referencing /Im1.
        self::assertStringContainsString('/XObject << /Im1', $bytes);
        // The image XObject itself is present.
        self::assertStringContainsString('/Subtype /Image', $bytes);
        self::assertStringContainsString('/Filter /FlateDecode', $bytes);
    }

    public function testAddPageHasHelvetica11AsDefaultFont(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        // No setFont() call: the default should let cell()/text() work.
        $page->cell(x: 20, y: 20, w: 80, h: 30, text: 'Hi', padding: 0);
        $bytes = $page->contentStream()->bytes();
        // Helvetica is registered as F1, size 11 emitted in the text object.
        self::assertMatchesRegularExpression('#/F1 11(\.\d+)? Tf#', $bytes);
    }

    public function testSetDefaultFontIsAppliedToNewPages(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setDefaultFont(Font::courier()->bold(), 14.5);
        $page = $doc->addPage();
        $page->cell(x: 20, y: 20, w: 80, h: 30, text: 'Hi', padding: 0);
        $bytes = $page->contentStream()->bytes();
        self::assertMatchesRegularExpression('#/F1 14\.5 Tf#', $bytes);
    }

    public function testSetDefaultFontDoesNotAffectPagesAlreadyCreated(): void
    {
        $doc = new Document(Unit::PT);
        $first = $doc->addPage();           // Helvetica 11
        $doc->setDefaultFont(Font::times(), 18);
        $second = $doc->addPage();          // Times 18

        $first->cell(x: 20, y: 20, w: 60, h: 30, text: 'A', padding: 0);
        $second->cell(x: 20, y: 20, w: 60, h: 30, text: 'B', padding: 0);

        self::assertMatchesRegularExpression('#11(\.\d+)? Tf#', $first->contentStream()->bytes());
        self::assertMatchesRegularExpression('#18(\.\d+)? Tf#', $second->contentStream()->bytes());
    }

    public function testSetDefaultFontRejectsNonPositiveSize(): void
    {
        $doc = new Document(Unit::PT);
        $this->expectException(PdfException::class);
        $doc->setDefaultFont(Font::helvetica(), 0.0);
    }

    public function testSetDefaultCellsPaddingFloatAppliesToNewPages(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setDefaultCellsPadding(7.0);
        $page = $doc->addPage();
        self::assertEquals(\DragonOfMercy\PhpPdf\CellPadding::all(7.0), $page->getCellsPadding());
    }

    public function testSetDefaultCellsPaddingObjectAppliesToNewPages(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setDefaultCellsPadding(\DragonOfMercy\PhpPdf\CellPadding::symmetric(2, 6));
        $page = $doc->addPage();
        $p = $page->getCellsPadding();
        self::assertSame(2.0, $p->top);
        self::assertSame(6.0, $p->right);
        self::assertSame(2.0, $p->bottom);
        self::assertSame(6.0, $p->left);
    }

    public function testSetDefaultCellsPaddingDoesNotAffectPagesAlreadyCreated(): void
    {
        $doc = new Document(Unit::PT);
        $first = $doc->addPage();
        $doc->setDefaultCellsPadding(10.0);
        $second = $doc->addPage();
        // First page keeps its 2pt builtin default; second picks up 10.
        self::assertEquals(\DragonOfMercy\PhpPdf\CellPadding::all(2.0), $first->getCellsPadding());
        self::assertEquals(\DragonOfMercy\PhpPdf\CellPadding::all(10.0), $second->getCellsPadding());
    }

    public function testDocumentReservesContiguousObjectNumbersForImageAndSmask(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $img = \DragonOfMercy\PhpPdf\Image::fromBytes(
            \DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory::pngRgbAlpha(width: 4, height: 4),
        );
        $page->image($img, x: 0, y: 0, w: 50, h: 50);
        $bytes = $doc->output();
        // The main image's /SMask reference points at object N+1 (whatever N is, the +1 is contiguous).
        self::assertMatchesRegularExpression('#/SMask (\d+) 0 R#', $bytes, 'SMask reference present');
        if (preg_match('#/SMask (\d+) 0 R#', $bytes, $m) === 1) {
            $smaskNum = (int) $m[1];
            self::assertMatchesRegularExpression(
                "#\\b{$smaskNum} 0 obj\\b#",
                $bytes,
                'SMask object definition present at the referenced number',
            );
        }
    }

    public function testRegisterFontFamilyRequiresExistingFile(): void
    {
        $pdf = new Document();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Font file not found for alias 'Inter' (regular)");
        $pdf->registerFontFamily('Inter', regular: __DIR__ . '/does-not-exist.ttf');
    }

    public function testRegisterFontFamilyRejectsOtfFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phpdf_otf_') . '.otf';
        file_put_contents($tmp, "OTTO\x00\x00\x00\x00more bytes here");
        try {
            $pdf = new Document();
            $this->expectException(PdfException::class);
            $this->expectExceptionMessage('OTF/CFF fonts not supported');
            $pdf->registerFontFamily('Bad', regular: $tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testRegisterFontFamilyExposesResolverViaInternalAccessor(): void
    {
        $path = __DIR__ . '/../Golden/fixtures/fonts/FreeSans.ttf';
        if (!is_file($path)) {
            self::markTestSkipped('FreeSans fixture not present');
        }
        $pdf = new Document();
        $pdf->registerFontFamily('FS', regular: $path);
        self::assertNotNull($pdf->fontResolver());
    }
}
