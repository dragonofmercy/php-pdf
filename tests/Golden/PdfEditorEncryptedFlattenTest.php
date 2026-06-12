<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\TextField;
use DragonOfMercy\PhpPdf\PdfEditor;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use PHPUnit\Framework\TestCase;

/**
 * Flattening an ENCRYPTED form: the burned content streams and re-emitted page
 * objects of the appended revision must be re-encrypted under the source key,
 * so re-reading with the password yields a frozen form (no /AcroForm) whose page
 * content still decrypts.
 */
final class PdfEditorEncryptedFlattenTest extends TestCase
{
    private static function encryptedFormBytes(): string
    {
        $doc = new Document();
        $doc->encryption()->userPassword('test')->ownerPassword('test'); // AES-256 (the library's only write scheme)
        $page = $doc->addPage();
        $page->field(new TextField(20.0, 20.0, 80.0, 8.0, name: 'name'));
        return $doc->output();
    }

    public function testFlattenEncryptedFormRoundTrips(): void
    {
        $editor = PdfEditor::fromBytes(self::encryptedFormBytes(), 'test');
        $editor->setField('name', 'Hello');
        $editor->flattenFields();
        $out = $editor->output();

        $reader = PdfReader::fromBytes($out, 'test');
        self::assertTrue($reader->isEncrypted(), 'flattened file should stay encrypted');
        self::assertNull($reader->catalog()->get(Name::of('AcroForm')), '/AcroForm should be gone');

        // Page-1 content (burned appearance) decrypts and carries a Do operator.
        $page = $reader->page(1);
        $content = '';
        foreach ($page->contents as $ref) {
            $stream = $reader->resolve($ref);
            if ($stream instanceof ReadStream) {
                $content .= $reader->decodeStream($stream);
            }
        }
        self::assertStringContainsString('Do', $content, 'burned appearance must decrypt');
    }
}
