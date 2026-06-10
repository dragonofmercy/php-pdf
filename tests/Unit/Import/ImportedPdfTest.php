<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Import;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Import\ImportedPageTemplate;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class ImportedPdfTest extends TestCase
{
    private static function sourcePdfBytes(): string
    {
        // a real one-page PDF produced by the library itself
        $doc = new Document(Unit::PT);
        $doc->addPage();
        return $doc->output();
    }

    public function testImportExposesPagesAndCaches(): void
    {
        $doc = new Document();
        $source = $doc->importPdfBytes(self::sourcePdfBytes());
        self::assertSame(1, $source->pageCount());
        $template = $source->page(1);
        self::assertInstanceOf(ImportedPageTemplate::class, $template);
        self::assertSame($template, $source->page(1)); // cached per page number
    }

    public function testTemplateExposesVisualSizeInPoints(): void
    {
        $doc = new Document();
        $template = $doc->importPdfBytes(self::sourcePdfBytes())->page(1);
        // library default page is A4 portrait: 595.28 x 841.89 pt
        self::assertEqualsWithDelta(595.28, $template->widthPt(), 0.01);
        self::assertEqualsWithDelta(841.89, $template->heightPt(), 0.01);
    }

    public function testPageOutOfRangeThrows(): void
    {
        $doc = new Document();
        $source = $doc->importPdfBytes(self::sourcePdfBytes());
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('out of range');
        $source->page(2);
    }

    public function testEncryptedSourceIsRejected(): void
    {
        $doc = new Document(Unit::PT);
        $doc->metadata()->documentId('abcdef0123456789abcdef0123456789');
        $doc->encryption()
            ->userPassword('user')
            ->ownerPassword('owner')
            ->withRandomSource(fn (int $n) => str_repeat("\x00", $n));
        $doc->addPage();
        $encrypted = $doc->output();

        $target = new Document();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('ncrypted');
        $target->importPdfBytes($encrypted);
    }

    public function testImportPdfReadsAFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phppdf_import_');
        self::assertIsString($path);
        try {
            file_put_contents($path, self::sourcePdfBytes());
            $doc = new Document();
            self::assertSame(1, $doc->importPdf($path)->pageCount());
        } finally {
            @unlink($path);
        }
    }
}
