<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Reader;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end coverage for the reader's transparent decryption: build an
 * encrypted document with the library's own AES-256 writer, then read it back
 * and assert the plaintext surfaces. No external tool is involved - the library
 * is both producer and consumer.
 */
final class PdfReaderEncryptedTest extends TestCase
{
    public function testReadsBackOwnAes256EncryptedDocument(): void
    {
        $doc = new Document();
        $doc->metadata()->title('Secret marker');
        $doc->addPage();
        $doc->encryption()
            ->userPassword('')
            ->ownerPassword('owner-secret');
        $bytes = $doc->output();

        $reader = PdfReader::fromBytes($bytes); // empty user password
        self::assertTrue($reader->isEncrypted());

        $title = self::resolveTitle($reader);
        self::assertStringContainsString('Secret marker', $title);
    }

    public function testNonEncryptedDocumentReadsNormally(): void
    {
        $doc = new Document();
        $doc->metadata()->title('Plain marker');
        $doc->addPage();
        $bytes = $doc->output();

        $reader = PdfReader::fromBytes($bytes);
        self::assertFalse($reader->isEncrypted());

        // The catalog still resolves and the page tree is reachable.
        $catalog = $reader->catalog();
        self::assertInstanceOf(Dictionary::class, $catalog);
        self::assertSame(1, $reader->pageCount());

        self::assertStringContainsString('Plain marker', self::resolveTitle($reader));
    }

    public function testWrongPasswordThrows(): void
    {
        $doc = new Document();
        $doc->addPage();
        $doc->encryption()
            ->userPassword('correct-horse')
            ->ownerPassword('owner-secret');
        $bytes = $doc->output();

        $this->expectException(PdfException::class);
        PdfReader::fromBytes($bytes, 'wrong');
    }

    public function testRightUserPasswordOpens(): void
    {
        $doc = new Document();
        $doc->metadata()->title('Guarded marker');
        $doc->addPage();
        $doc->encryption()
            ->userPassword('correct-horse')
            ->ownerPassword('owner-secret');
        $bytes = $doc->output();

        $reader = PdfReader::fromBytes($bytes, 'correct-horse');
        self::assertTrue($reader->isEncrypted());

        self::assertStringContainsString('Guarded marker', self::resolveTitle($reader));
    }

    /**
     * Resolves the document /Info -> /Title and returns its decoded UTF-8 text.
     */
    private static function resolveTitle(PdfReader $reader): string
    {
        $infoRef = $reader->trailer()->get(Name::of('Info'));
        self::assertNotNull($infoRef);
        $info = $reader->resolve($infoRef);
        self::assertInstanceOf(Dictionary::class, $info);

        $titleRef = $info->get(Name::of('Title'));
        self::assertNotNull($titleRef);
        return self::decodeTextString($reader->resolve($titleRef));
    }

    /**
     * Returns a UTF-8 view of a decrypted PDF text string. Text strings are
     * stored as a UTF-16BE sequence prefixed with the \xFE\xFF BOM; strip the
     * BOM and transcode. PdfString and HexString both surface their raw bytes.
     */
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
