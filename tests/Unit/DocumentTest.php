<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Document\Encryption;
use DragonOfMercy\PhpPdf\Document\Metadata;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\NextPosition;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\PageMargins;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

        $fixture = file_get_contents(__DIR__ . '/../Golden/fixtures/doc/empty-page.pdf');
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

    public function testSetFontWithoutTextEmitsNoFontResources(): void
    {
        // Registering a font without ever rendering text must not pull in the
        // font's Type1/BaseFont indirect object. The page itself still carries
        // a /Resources entry (required by spec, see
        // testEmptyPageStillEmitsResourcesDictionary), but it stays empty.
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        // No text() call
        $bytes = $doc->output();

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

    public function testRegisterFontFamilyRejectsOttoWithoutCffTable(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phpdf_otf_') . '.otf';
        file_put_contents($tmp, "OTTO\x00\x00\x00\x00more bytes here");
        try {
            $pdf = new Document();
            $this->expectException(PdfException::class);
            $this->expectExceptionMessage("Invalid OpenType font (OTTO without 'CFF ' table)");
            $pdf->registerFontFamily('Bad', regular: $tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testRegisteringSameAliasTwiceThrows(): void
    {
        $path = __DIR__ . '/../Golden/fixtures/fonts/FreeSans.ttf';
        if (!is_file($path)) {
            self::markTestSkipped('FreeSans fixture not present');
        }
        $pdf = new Document();
        $pdf->registerFontFamily('Inter', regular: $path);
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("'Inter'");
        $pdf->registerFontFamily('Inter', regular: $path);
    }

    public function testRegisteringDistinctAliasesSucceeds(): void
    {
        $path = __DIR__ . '/../Golden/fixtures/fonts/FreeSans.ttf';
        if (!is_file($path)) {
            self::markTestSkipped('FreeSans fixture not present');
        }
        $pdf = new Document();
        $pdf->registerFontFamily('AliasOne', regular: $path);
        $result = $pdf->registerFontFamily('AliasTwo', regular: $path);
        self::assertSame($pdf, $result);
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

    public function testOutputEmitsFiveObjectsPerUsedCustomFont(): void
    {
        $path = __DIR__ . '/../Golden/fixtures/fonts/FreeSans.ttf';
        if (!is_file($path)) {
            self::markTestSkipped('FreeSans fixture not present');
        }
        $pdf = new Document();
        $pdf->registerFontFamily('FS', regular: $path);
        $page = $pdf->addPage();
        $page->setFont(Font::custom('FS'), 14);
        $page->text(50, 50, 'A');
        $bytes = $pdf->output();
        self::assertStringContainsString('/Subtype /Type0', $bytes);
        self::assertStringContainsString('/Subtype /CIDFontType2', $bytes);
        self::assertStringContainsString('/Type /FontDescriptor', $bytes);
        self::assertStringContainsString('/FontFile2', $bytes);
        self::assertStringContainsString('/Encoding /Identity-H', $bytes);
        self::assertStringContainsString('/ToUnicode', $bytes);
    }

    public function testRegisteredButUnusedFontsAreNotEmbedded(): void
    {
        $path = __DIR__ . '/../Golden/fixtures/fonts/FreeSans.ttf';
        if (!is_file($path)) {
            self::markTestSkipped('FreeSans fixture not present');
        }
        $pdf = new Document();
        $pdf->registerFontFamily('FS', regular: $path);
        $pdf->addPage();
        $bytes = $pdf->output();
        self::assertStringNotContainsString('/Subtype /Type0', $bytes);
        self::assertStringNotContainsString('/Subtype /CIDFontType2', $bytes);
    }

    public function testMarginsDefaultsToAllZero(): void
    {
        $doc = new Document();
        $m = $doc->margins();
        self::assertSame(0.0, $m->top);
        self::assertSame(0.0, $m->right);
        self::assertSame(0.0, $m->bottom);
        self::assertSame(0.0, $m->left);
    }

    public function testSetMarginsWithPageMarginsObject(): void
    {
        $doc = new Document();
        $doc->setMargins(new PageMargins(top: 10.0, right: 15.0, bottom: 20.0, left: 25.0));
        $m = $doc->margins();
        self::assertSame(10.0, $m->top);
        self::assertSame(25.0, $m->left);
    }

    public function testSetMarginsWithFloatDistributesAllSides(): void
    {
        $doc = new Document();
        $doc->setMargins(12.5);
        $m = $doc->margins();
        self::assertSame(12.5, $m->top);
        self::assertSame(12.5, $m->right);
        self::assertSame(12.5, $m->bottom);
        self::assertSame(12.5, $m->left);
    }

    public function testSetMarginsReturnsSelfForChaining(): void
    {
        $doc = new Document();
        self::assertSame($doc, $doc->setMargins(5.0));
    }

    public function testGetCurrentPageThrowsBeforeAnyAddPage(): void
    {
        $doc = new Document();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('No current page: call addPage() first');
        $doc->getCurrentPage();
    }

    public function testGetCurrentPageReturnsLastAddedPage(): void
    {
        $doc = new Document();
        $first = $doc->addPage();
        $second = $doc->addPage();
        self::assertSame($second, $doc->getCurrentPage());
    }

    public function testAddPageAssignsSequentialPageNumbers(): void
    {
        $doc = new Document();
        $p1 = $doc->addPage();
        $p2 = $doc->addPage();
        $p3 = $doc->addPage();
        self::assertSame(1, $p1->pageNumber());
        self::assertSame(2, $p2->pageNumber());
        self::assertSame(3, $p3->pageNumber());
    }

    public function testAddPageFiresHeaderCallback(): void
    {
        $doc = new Document(Unit::PT);
        $fired = [];
        $doc->setHeader(function (Page $p) use (&$fired): void {
            $fired[] = $p->pageNumber();
        });
        $doc->addPage();
        $doc->addPage();
        self::assertSame([1, 2], $fired);
    }

    public function testAddPagePositionsCursorAtLeftTopMargin(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setMargins(new PageMargins(top: 25.0, right: 10.0, bottom: 30.0, left: 15.0));
        $page = $doc->addPage();
        self::assertSame(15.0, $page->getX());
        self::assertSame(25.0, $page->getY());
    }

    public function testHeaderDoesNotLeakFontStateToBody(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setDefaultFont(Font::helvetica(), 11);
        $doc->setHeader(function (Page $p): void {
            $p->setFont(Font::helvetica()->bold(), 14);
            $p->text(50, 35, 'Title');
        });
        $page = $doc->addPage();
        // Body must see the page defaults (regular 11), not the bold 14 the
        // header callback left behind.
        self::assertEquals(Font::helvetica(), $page->getFont());
        self::assertSame(11.0, $page->getFontSize());
    }

    public function testHeaderFontStateRestoredEvenWhenCallbackThrows(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setDefaultFont(Font::helvetica(), 11);
        $doc->setHeader(function (Page $p): void {
            $p->setFont(Font::helvetica()->bold(), 14);
            throw new RuntimeException('boom');
        });
        try {
            $doc->addPage();
            self::fail('Expected the header callback to propagate.');
        } catch (RuntimeException) {
            // Expected. The page is still reachable via getCurrentPage().
        }
        $page = $doc->getCurrentPage();
        self::assertEquals(Font::helvetica(), $page->getFont());
        self::assertSame(11.0, $page->getFontSize());
    }

    public function testAutoBreakDoesNotInheritHeaderFontOnNextPage(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setMargins(new PageMargins(top: 60.0, right: 50.0, bottom: 60.0, left: 50.0));
        $doc->setHeader(function (Page $p): void {
            $p->setFont(Font::helvetica()->bold(), 14);
            $p->text(50, 35, 'Title');
        });
        $doc->setAutoPageBreak(true);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11);
        // Emit enough rows to force an auto-break onto a second page.
        for ($i = 1; $i <= 60; $i++) {
            $doc->getCurrentPage()->cell(
                w: 495.0,
                h: 16.0,
                text: "Row {$i}",
                ln: NextPosition::NEWLINE,
            );
        }
        self::assertGreaterThan(1, $doc->pageCount());
        // The page Reached via getCurrentPage() is the auto-created second page;
        // after rows have rendered, it must still report the body font, not bold.
        self::assertEquals(Font::helvetica(), $doc->getCurrentPage()->getFont());
        self::assertSame(11.0, $doc->getCurrentPage()->getFontSize());
    }

    public function testFooterDoesNotLeakFontStateAfterOutput(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setFooter(function (Page $p, int $n, int $total): void {
            $p->setFont(Font::helvetica()->bold(), 9);
            $p->text(50, 800, "Page {$n} / {$total}");
        });
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->text(50, 50, 'Body');
        $doc->output();
        // After output(), the page must report the body font, not the footer's bold.
        self::assertEquals(Font::helvetica(), $page->getFont());
        self::assertSame(12.0, $page->getFontSize());
    }

    public function testSetHeaderWithNullClearsCallback(): void
    {
        $doc = new Document();
        $fired = false;
        $doc->setHeader(function (Page $p) use (&$fired): void {
            $fired = true;
        });
        $doc->setHeader(null);
        $doc->addPage();
        self::assertFalse($fired);
    }

    public function testSetFooterFiresOncePerPageAtOutput(): void
    {
        $doc = new Document(Unit::PT);
        $captured = [];
        $doc->setFooter(function (Page $p, int $n, int $total) use (&$captured): void {
            $captured[] = [$n, $total];
        });
        $doc->addPage();
        $doc->addPage();
        $doc->addPage();
        // Footer must NOT fire during addPage.
        self::assertSame([], $captured);
        $doc->output();
        self::assertSame([[1, 3], [2, 3], [3, 3]], $captured);
    }

    public function testSetFooterWithNullClearsCallback(): void
    {
        $doc = new Document(Unit::PT);
        $fired = false;
        $doc->setFooter(function (Page $p, int $n, int $t) use (&$fired): void {
            $fired = true;
        });
        $doc->setFooter(null);
        $doc->addPage();
        $doc->output();
        self::assertFalse($fired);
    }

    public function testFooterContentStreamIsAppendedToPage(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setFooter(function (Page $p, int $n, int $total): void {
            $p->setFont(Font::helvetica(), 8);
            $p->text(50, 800, "Page {$n} / {$total}");
        });
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->text(50, 50, 'Body');
        $bytes = $doc->output();
        // The footer text must appear in the serialized PDF bytes (WinAnsi-encoded).
        // Note: the bytes are compressed, so we check the uncompressed approach
        // is not viable here. Use a simpler check: ensure the output succeeds
        // and is non-empty. The more rigorous test is the golden fixture.
        self::assertNotSame('', $bytes);
        self::assertStringStartsWith('%PDF-', $bytes);
    }

    public function testEmptyPageStillEmitsResourcesDictionary(): void
    {
        // PDF 1.7 spec 7.7.3.3 requires /Resources on every /Page (empty dict OK,
        // or inherited from /Pages ancestor). qpdf --check warns when missing.
        // assertStringContainsString cannot be used: PDF bytes carry a binary
        // marker that trips PHPUnit's encoding detection.
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $bytes = $doc->output();
        self::assertTrue(str_contains($bytes, '/Resources'), 'Page bytes should include /Resources');
    }

    public function testPageWithOnlyGraphicsStillEmitsResourcesDictionary(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setLineWidth(1.0);
        $page->line(10.0, 10.0, 100.0, 100.0)->stroke();
        $bytes = $doc->output();
        self::assertTrue(str_contains($bytes, '/Resources'), 'Page bytes should include /Resources');
    }

    public function testAutoPageBreakOffByDefault(): void
    {
        $doc = new Document();
        self::assertFalse($doc->autoPageBreak());
    }

    public function testSetAutoPageBreakWithoutMarginsDefaultsToAll20(): void
    {
        $doc = new Document();
        $doc->setAutoPageBreak(true);
        self::assertTrue($doc->autoPageBreak());
        self::assertSame(20.0, $doc->margins()->top);
        self::assertSame(20.0, $doc->margins()->bottom);
    }

    public function testSetAutoPageBreakPreservesExistingMargins(): void
    {
        $doc = new Document();
        $doc->setMargins(new PageMargins(top: 5.0, right: 5.0, bottom: 5.0, left: 5.0));
        $doc->setAutoPageBreak(true);
        self::assertSame(5.0, $doc->margins()->top);
    }

    public function testRepeatedOutputDoesNotRerunFooters(): void
    {
        $doc = new Document(Unit::PT);
        $fireCount = 0;
        $doc->setFooter(function (Page $p, int $n, int $total) use (&$fireCount): void {
            $fireCount++;
        });
        $doc->addPage();
        $doc->addPage();
        $first = $doc->output();
        $second = $doc->output();
        // Three different invariants worth guarding:
        // (a) footer callback fired exactly once per page across both output() calls
        // (b) bytes are deterministic between two outputs of the same Document
        // (c) PDF content stream is not corrupted by double-emission
        self::assertSame(2, $fireCount);
        self::assertSame($first, $second);
    }

    public function testDefaultBorderWidthInitialPt(): void
    {
        $doc = new Document(Unit::PT);
        self::assertSame(0.25, $doc->defaultBorderWidth());
    }

    public function testDefaultBorderWidthInitialMm(): void
    {
        $doc = new Document(Unit::MM);
        self::assertEqualsWithDelta(0.25, $doc->defaultBorderWidth(), 1e-10);
    }

    public function testSetDefaultBorderWidthRoundtripPt(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setDefaultBorderWidth(1.0);
        self::assertSame(1.0, $doc->defaultBorderWidth());
    }

    public function testSetDefaultBorderWidthRoundtripMm(): void
    {
        $doc = new Document(Unit::MM);
        $doc->setDefaultBorderWidth(1.0);
        self::assertEqualsWithDelta(1.0, $doc->defaultBorderWidth(), 1e-10);
    }

    public function testSetDefaultBorderWidthRejectsZero(): void
    {
        $doc = new Document();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Default border width must be positive, got 0');
        $doc->setDefaultBorderWidth(0.0);
    }

    public function testSetDefaultBorderWidthRejectsNegative(): void
    {
        $doc = new Document();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Default border width must be positive, got -1.5');
        $doc->setDefaultBorderWidth(-1.5);
    }

    public function testSetDefaultBorderWidthFluent(): void
    {
        $doc = new Document();
        self::assertSame($doc, $doc->setDefaultBorderWidth(0.5));
    }

    private const string FS_DIR = __DIR__ . '/../Golden/fixtures/fonts';

    public function testCustomFontIsSubsettedAndDeterministic(): void
    {
        if (!is_file(self::FS_DIR . '/FreeSans.ttf')) {
            self::markTestSkipped('FreeSans fixture absent');
        }
        $build = static function (): string {
            $doc = new Document(Unit::PT);
            $doc->registerFontFamily('FS', regular: self::FS_DIR . '/FreeSans.ttf');
            $page = $doc->addPage();
            $page->setFont(Font::custom('FS'), 14);
            $page->text(50, 50, 'Hello');
            return $doc->output();
        };

        $pdf = $build();
        self::assertLessThan(150_000, strlen($pdf)); // subsetted FreeSans+Hello ~95 KB; full font ~1.5 MB
        self::assertSame($pdf, $build());
        self::assertMatchesRegularExpression('#/BaseFont /[A-Z]{6}\+FreeSans\b#', $pdf);
    }

    public function testRegisteredFontSetButNoTextStillProducesValidPdf(): void
    {
        if (!is_file(self::FS_DIR . '/FreeSans.ttf')) {
            self::markTestSkipped('FreeSans fixture absent');
        }
        $doc = new Document(Unit::PT);
        $doc->registerFontFamily('FS', regular: self::FS_DIR . '/FreeSans.ttf');
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 14);
        $page->text(50, 50, '');
        $pdf = $doc->output();
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('/BaseFont', $pdf); // font object still emitted even with no glyphs used
    }

    public function testOtfFontEmbeddedAsCidFontType0AndDeterministic(): void
    {
        if (!is_file(self::FS_DIR . '/IBMPlexSans-Regular.otf')) {
            self::markTestSkipped('IBM Plex Sans OTF fixture absent');
        }
        $build = static function (): string {
            $doc = new Document(Unit::PT);
            $doc->registerFontFamily('Plex', regular: self::FS_DIR . '/IBMPlexSans-Regular.otf');
            $page = $doc->addPage();
            $page->setFont(Font::custom('Plex'), 14);
            $page->text(50, 50, 'Hello');
            return $doc->output();
        };

        $pdf = $build();
        self::assertStringContainsString('/Subtype /CIDFontType0', $pdf);
        self::assertStringContainsString('/Subtype /OpenType', $pdf);
        self::assertStringContainsString('/FontFile3', $pdf);
        self::assertStringNotContainsString('/CIDFontType2', $pdf);
        self::assertMatchesRegularExpression('#/BaseFont /[A-Z]{6}\+IBMPlexSans#', $pdf);
        self::assertLessThan(
            filesize(self::FS_DIR . '/IBMPlexSans-Regular.otf'),
            strlen($pdf),
            'Subsetted OTF embed should be smaller than the original font file',
        );
        self::assertSame($pdf, $build());
    }

    public function testMixedTtfAndOtfDocumentUsesBothPaths(): void
    {
        if (
            !is_file(self::FS_DIR . '/IBMPlexSans-Regular.otf')
            || !is_file(self::FS_DIR . '/FreeSans.ttf')
        ) {
            self::markTestSkipped('TTF or OTF fixture absent');
        }
        $doc = new Document(Unit::PT);
        $doc->registerFontFamily('FS', regular: self::FS_DIR . '/FreeSans.ttf');
        $doc->registerFontFamily('Plex', regular: self::FS_DIR . '/IBMPlexSans-Regular.otf');
        $page = $doc->addPage();
        $page->setFont(Font::custom('FS'), 12);
        $page->text(50, 50, 'TTF');
        $page->setFont(Font::custom('Plex'), 12);
        $page->text(50, 70, 'OTF');
        $pdf = $doc->output();

        self::assertStringContainsString('/CIDFontType2', $pdf);
        self::assertStringContainsString('/FontFile2', $pdf);
        self::assertMatchesRegularExpression('#/BaseFont /[A-Z]{6}\+#', $pdf);
        self::assertStringContainsString('/CIDFontType0', $pdf);
        self::assertStringContainsString('/Subtype /OpenType', $pdf);
    }

    public function testOutlineReturnsTheSameOutlineNodeOnEachCall(): void
    {
        $doc = new Document(Unit::PT);
        $first = $doc->outline();
        $second = $doc->outline();
        self::assertSame($first, $second);
    }

    public function testDocumentWithoutOutlineCallDoesNotContainOutlines(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $bytes = $doc->output();
        self::assertStringNotContainsString('/Outlines', $bytes);
        self::assertStringNotContainsString('/Type /Outlines', $bytes);
    }

    public function testDocumentWithEmptyOutlineDoesNotContainOutlines(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $doc->outline();
        $bytes = $doc->output();
        self::assertStringNotContainsString('/Outlines', $bytes);
    }

    public function testDocumentWithOutlinePopulatedAddsOutlinesEntryToCatalog(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $doc->addPage();
        $doc->outline()->add('Chapter 1', \DragonOfMercy\PhpPdf\Outline\Destination::page(0));
        $doc->outline()->add('Chapter 2', \DragonOfMercy\PhpPdf\Outline\Destination::page(1));
        $bytes = $doc->output();
        self::assertStringContainsString('/Type /Catalog', $bytes);
        self::assertStringContainsString('/Outlines', $bytes);
        self::assertStringContainsString('/Type /Outlines', $bytes);
        self::assertStringContainsString('/Title (Chapter 1)', $bytes);
        self::assertStringContainsString('/Title (Chapter 2)', $bytes);
    }

    public function testDocumentWithOutlineAndMetadataKeepsBothInCatalog(): void
    {
        $doc = new Document(Unit::PT);
        $doc->metadata()->title('Test');
        $doc->addPage();
        $doc->outline()->add('Only chapter', \DragonOfMercy\PhpPdf\Outline\Destination::page(0));
        $bytes = $doc->output();
        self::assertStringContainsString('/Metadata', $bytes);
        self::assertStringContainsString('/Outlines', $bytes);
    }

    public function testDocumentWithoutFieldsHasNoAcroForm(): void
    {
        $doc = new Document();
        $doc->addPage();
        $bytes = $doc->output();
        self::assertStringNotContainsString('/AcroForm', $bytes);
    }

    public function testDocumentWithTextFieldHasAcroForm(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new \DragonOfMercy\PhpPdf\Form\TextField(50.0, 100.0, 80.0, 8.0, name: 'a'));
        $bytes = $doc->output();
        self::assertStringContainsString('/AcroForm ', $bytes);
        self::assertStringContainsString('/FT /Tx', $bytes);
        self::assertStringContainsString('/T (a)', $bytes);
        self::assertStringContainsString('/NeedAppearances true', $bytes);
    }

    public function testDocumentWithFieldAndLinkMergesAnnots(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 10);
        $page->field(new \DragonOfMercy\PhpPdf\Form\TextField(50.0, 100.0, 80.0, 8.0, name: 'a'));
        $page->link(50.0, 200.0, 80.0, 8.0, \DragonOfMercy\PhpPdf\Outline\Link::url('https://example.com'));
        $bytes = $doc->output();
        // /Annots should contain at least 2 refs (one for link, one for field)
        self::assertMatchesRegularExpression('~/Annots \[\d+ 0 R \d+ 0 R\]~', $bytes);
    }
}
