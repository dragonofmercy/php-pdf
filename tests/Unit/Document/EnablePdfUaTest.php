<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Document;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class EnablePdfUaTest extends TestCase
{
    public function testEnablePdfUaTurnsOnTaggingAndUaFlag(): void
    {
        $doc = new Document();
        $doc->enablePdfUA('en-US');
        self::assertTrue($doc->isTaggingEnabled());
        self::assertTrue($doc->isPdfUA());
        self::assertSame('en-US', $doc->language());
    }

    public function testEnablePdfUaIsFluent(): void
    {
        $doc = new Document();
        self::assertSame($doc, $doc->enablePdfUA());
    }

    public function testEnablePdfUaRejectsBadLanguage(): void
    {
        $doc = new Document();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('en_US!');
        $doc->enablePdfUA('en_US!');
    }

    public function testEnablePdfUaIsIdempotent(): void
    {
        $doc = new Document();
        $doc->enablePdfUA()->enablePdfUA();
        self::assertTrue($doc->isPdfUA());
    }

    public function testNonUaDocumentIsNotPdfUa(): void
    {
        $doc = new Document();
        self::assertFalse($doc->isPdfUA());
        $doc->enableTagging();
        self::assertFalse($doc->isPdfUA());
    }
}
