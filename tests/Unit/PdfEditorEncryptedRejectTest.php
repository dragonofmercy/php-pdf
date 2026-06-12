<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\PdfEditor;
use PHPUnit\Framework\TestCase;

/**
 * Editing an encrypted PDF is not supported in this unit; PdfEditor rejects it
 * with a clear message. The source uses an empty user password so the
 * isEncrypted() guard is reached (a non-empty user password would instead make
 * the reader throw 'password required' first, which is also acceptable since
 * the user still learns the file is encrypted).
 */
final class PdfEditorEncryptedRejectTest extends TestCase
{
    public function testFromBytesRejectsEncryptedDocument(): void
    {
        $doc = new Document();
        $doc->addPage();
        $doc->encryption()
            ->userPassword('')
            ->ownerPassword('owner-secret');
        $encryptedBytes = $doc->output();

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/encrypted.*not yet supported/i');

        PdfEditor::fromBytes($encryptedBytes);
    }
}
