<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Import;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Modify\EditRevisionBuilder;
use DragonOfMercy\PhpPdf\PdfEditor;
use DragonOfMercy\PhpPdf\Signature\RevisionContext;
use PHPUnit\Framework\TestCase;

final class PdfRevisionContextTest extends TestCase
{
    private function builderFor(PdfEditor $pdf): EditRevisionBuilder
    {
        $builder = (new \ReflectionMethod($pdf, 'revisionBuilder'))->invoke($pdf);
        self::assertInstanceOf(EditRevisionBuilder::class, $builder);
        return $builder;
    }

    public function testContextFromSourceNoEdits(): void
    {
        $doc = new Document();
        $doc->addPage();
        $pdf = PdfEditor::fromBytes($doc->output());
        $result = $this->builderFor($pdf)->buildSigningBase();
        $ctx = $result['context'];
        $bytes = $result['bytes'];
        self::assertInstanceOf(RevisionContext::class, $ctx);
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
        $result = $this->builderFor($pdf)->buildSigningBase();
        $bytes = $result['bytes'];
        self::assertGreaterThan(strlen($base), strlen($bytes));
        self::assertStringContainsString('Edited', $bytes);
    }
}
