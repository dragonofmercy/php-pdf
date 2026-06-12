<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Import;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Import\ImportedPdf;
use DragonOfMercy\PhpPdf\Import\ImportedPageTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Importing an encrypted source = decrypt-then-template. The library encrypts a
 * document with its own AES-256 writer (empty user password), then a fresh
 * destination Document imports those bytes and reaches the decrypted pages.
 */
final class ImportEncryptedTest extends TestCase
{
    public function testImportPdfBytesDecryptsEncryptedSource(): void
    {
        $src = new Document();
        $src->metadata()->title('Confidential marker');
        $src->addPage();
        $src->addPage();
        $src->encryption()
            ->userPassword('')
            ->ownerPassword('owner-secret');
        $bytes = $src->output();

        $imported = (new Document())->importPdfBytes($bytes);

        self::assertInstanceOf(ImportedPdf::class, $imported);
        self::assertSame(2, $imported->pageCount());
        self::assertInstanceOf(ImportedPageTemplate::class, $imported->page(1));
        self::assertInstanceOf(ImportedPageTemplate::class, $imported->page(2));
    }
}
