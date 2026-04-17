<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit;

use PhpPdf\Document;
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
}
