<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class DocumentJavaScriptTest extends TestCase
{
    public function testDocumentScriptEmitsNamesJavaScriptTree(): void
    {
        $doc = new Document();
        $doc->addPage();
        $doc->addDocumentScript('zeta', 'var z = 1;');
        $doc->addDocumentScript('alpha', 'var a = 1;');
        $bytes = $doc->output();

        self::assertStringContainsString('/Names', $bytes);
        self::assertStringContainsString('/JavaScript', $bytes);
        self::assertStringContainsString('var a = 1;', $bytes);
        self::assertStringContainsString('var z = 1;', $bytes);
        // Keys sorted: "alpha" must appear before "zeta" in the name array.
        $posAlpha = strpos($bytes, '(alpha)');
        $posZeta = strpos($bytes, '(zeta)');
        self::assertNotFalse($posAlpha);
        self::assertNotFalse($posZeta);
        self::assertLessThan($posZeta, $posAlpha);
    }

    public function testNoNamesEntryWithoutScripts(): void
    {
        $doc = new Document();
        $doc->addPage();
        self::assertStringNotContainsString('/JavaScript', $doc->output());
    }

    public function testEmptyNameThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Document script name cannot be empty');
        (new Document())->addDocumentScript('', 'x();');
    }

    public function testDuplicateNameThrows(): void
    {
        $doc = new Document();
        $doc->addDocumentScript('a', 'x();');
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Document script name 'a' is already registered");
        $doc->addDocumentScript('a', 'y();');
    }

    public function testEmptyJsThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Document script JavaScript cannot be empty');
        (new Document())->addDocumentScript('a', '');
    }

    public function testDocumentScriptSurvivesWithMetadataPath(): void
    {
        $doc = new Document();
        $doc->addPage();
        $doc->metadata()->title('T');
        $doc->addDocumentScript('init', 'app.alert("hi");');
        $bytes = $doc->output();
        self::assertStringContainsString('/Names', $bytes);
        self::assertStringContainsString('app.alert', $bytes);
    }
}
