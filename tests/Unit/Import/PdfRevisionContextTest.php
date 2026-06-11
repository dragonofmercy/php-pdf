<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Import;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\PdfEditor;
use DragonOfMercy\PhpPdf\Signature\RevisionContext;
use PHPUnit\Framework\TestCase;

final class PdfRevisionContextTest extends TestCase
{
    public function testContextFromSourceNoEdits(): void
    {
        $doc = new Document();
        $doc->addPage();
        $pdf = PdfEditor::fromBytes($doc->output());
        $m = new \ReflectionMethod($pdf, 'buildSigningBase');
        $result = $m->invoke($pdf);
        self::assertIsArray($result);
        $ctx = $result['context'];
        $bytes = $result['bytes'];
        self::assertInstanceOf(RevisionContext::class, $ctx);
        self::assertIsString($bytes);
        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertNotSame('', $ctx->documentId);
        self::assertGreaterThan(0, $ctx->maxObjectNumber);
    }

    public function testContextAfterMetadataEditAdvancesBytes(): void
    {
        $doc = new Document();
        // Source carries an XMP /Metadata stream so the metadata-edit revision
        // re-emits the XMP packet (where the new title appears as literal XML
        // text); /Info strings alone are stored UTF-16BE hex, never literal.
        $doc->metadata()->title('Orig');
        $doc->addPage();
        $base = $doc->output();
        $pdf = PdfEditor::fromBytes($base);
        $pdf->setTitle('Edited');
        $m = new \ReflectionMethod($pdf, 'buildSigningBase');
        $result = $m->invoke($pdf);
        self::assertIsArray($result);
        $bytes = $result['bytes'];
        self::assertIsString($bytes);
        self::assertGreaterThan(strlen($base), strlen($bytes));
        self::assertStringContainsString('Edited', $bytes);
    }
}
