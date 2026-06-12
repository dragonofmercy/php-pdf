<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\PdfEditor;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use PHPUnit\Framework\TestCase;

/**
 * Editing an ENCRYPTED PDF: the appended incremental revision is re-encrypted
 * with the recovered file key/scheme, and the source /Encrypt + /ID are
 * forwarded into the revision trailer, so the edited file stays a valid
 * encrypted PDF. The library is both producer (AES-256 writer) and consumer
 * (reader's transparent decryption) - no external tool is involved.
 */
final class PdfEditorEncryptedEditTest extends TestCase
{
    public function testEditingAes256EncryptedDocumentReEncryptsRevision(): void
    {
        $encryptedBytes = self::buildEncryptedDocument('Original title');

        $editor = PdfEditor::fromBytes($encryptedBytes); // empty user password
        $editor->setTitle('Edited title');
        $out = $editor->output();

        $reader = PdfReader::fromBytes($out); // empty user password
        self::assertTrue($reader->isEncrypted());

        // The revision's /Info -> /Title decrypts to the edited value.
        self::assertStringContainsString('Edited title', self::resolveTitle($reader));

        // The original page tree still resolves: the revision is valid and the
        // original bytes are untouched.
        self::assertSame(1, $reader->pageCount());
    }

    public function testEditedEncryptedInfoTitleIsNotStoredInClear(): void
    {
        $encryptedBytes = self::buildEncryptedDocument('Original title');

        $editor = PdfEditor::fromBytes($encryptedBytes);
        $editor->setTitle('Edited title');
        $out = $editor->output();

        // The /Info /Title is a PDF string and MUST be encrypted: neither the
        // UTF-8 nor the UTF-16BE form of the edited value may appear in the clear
        // bytes. (The XMP /Metadata stream is exempt: the library defaults to
        // /EncryptMetadata false, so it legitimately stays in the clear, matching
        // the source.)
        $reader = PdfReader::fromBytes($out);
        $infoRef = $reader->trailer()->get(Name::of('Info'));
        self::assertInstanceOf(PdfReference::class, $infoRef);
        $rawInfoObject = self::rawIndirectObjectBytes($out, $infoRef->objectNumber);
        self::assertStringNotContainsString('Edited title', $rawInfoObject);
        self::assertStringNotContainsString("E\x00d\x00i\x00t\x00e\x00d", $rawInfoObject);
    }

    /**
     * Extracts the raw (still-encrypted) bytes of an indirect object's body from
     * the serialized PDF, so an assertion can prove the string was not written in
     * the clear.
     */
    private static function rawIndirectObjectBytes(string $pdf, int $objectNumber): string
    {
        // The appended revision overwrites the source /Info at the same object
        // number, so take the LAST occurrence (strrpos, not strpos) to inspect
        // the newly written object rather than the stale source one.
        $start = strrpos($pdf, "\n{$objectNumber} 0 obj");
        self::assertNotFalse($start);
        $end = strpos($pdf, 'endobj', $start);
        self::assertNotFalse($end);
        return substr($pdf, $start, $end - $start);
    }

    public function testSigningEncryptedThrows(): void
    {
        $encryptedBytes = self::buildEncryptedDocument('Original title');

        $editor = PdfEditor::fromBytes($encryptedBytes);
        $editor->addSignature(self::selfSignedCertificate());

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/signing an encrypted PDF is not yet supported/i');
        $editor->output();
    }

    public function testNonEncryptedEditStillWorks(): void
    {
        $doc = new Document();
        $doc->metadata()->title('Plain marker');
        $doc->addPage();
        $bytes = $doc->output();

        $editor = PdfEditor::fromBytes($bytes);
        $editor->setTitle('Edited plain');
        $out = $editor->output();

        $reader = PdfReader::fromBytes($out);
        self::assertFalse($reader->isEncrypted());
        self::assertStringContainsString('Edited plain', self::resolveTitle($reader));
        self::assertSame(1, $reader->pageCount());
    }

    private static function buildEncryptedDocument(string $title): string
    {
        $doc = new Document();
        $doc->metadata()->title($title);
        $doc->addPage();
        $doc->encryption()
            ->userPassword('')
            ->ownerPassword('owner-secret');
        return $doc->output();
    }

    private static function selfSignedCertificate(): SigningCertificate
    {
        $generated = TestCertificate::generate();
        return SigningCertificate::fromPkcs12Bytes($generated['p12'], $generated['password']);
    }

    private static function resolveTitle(PdfReader $reader): string
    {
        $infoRef = $reader->trailer()->get(Name::of('Info'));
        self::assertInstanceOf(PdfReference::class, $infoRef);
        $info = $reader->resolve($infoRef);
        self::assertInstanceOf(Dictionary::class, $info);

        $titleRef = $info->get(Name::of('Title'));
        self::assertNotNull($titleRef);
        return self::decodeTextString($reader->resolve($titleRef));
    }

    private static function decodeTextString(PdfObject $value): string
    {
        if ($value instanceof HexString) {
            $bin = hex2bin($value->hex());
            self::assertIsString($bin);
            $raw = $bin;
        } elseif ($value instanceof PdfString) {
            $raw = $value->value();
        } else {
            $raw = '';
        }

        if (str_starts_with($raw, "\xFE\xFF")) {
            return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }

        return $raw;
    }
}
