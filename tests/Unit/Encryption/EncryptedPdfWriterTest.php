<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Encryption;

use PhpPdf\Encryption\Cipher;
use PhpPdf\Encryption\EncryptedPdfWriter;
use PhpPdf\Encryption\ObjectTransformer;
use PhpPdf\Writer\Object\Dictionary;
use PhpPdf\Writer\Object\IndirectObject;
use PhpPdf\Writer\Object\Name;
use PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class EncryptedPdfWriterTest extends TestCase
{
    public function testHeaderEofAndEncryptRefInTrailer(): void
    {
        $catalog = IndirectObject::of(
            1,
            0,
            Dictionary::empty()->withEntry(Name::of('Type'), Name::of('Catalog')),
        );
        $encrypt = IndirectObject::of(
            2,
            0,
            Dictionary::empty()->withEntry(Name::of('Filter'), Name::of('Standard')),
        );
        $transformer = new ObjectTransformer(
            cipher: new Cipher(),
            fileKey: str_repeat("\x01", 32),
            randomSource: fn (int $n) => str_repeat("\x00", $n),
            encryptObjectNumber: 2,
            metadataObjectNumber: null,
            encryptMetadata: false,
        );
        $bytes = (new EncryptedPdfWriter())->write(
            objects: [$catalog, $encrypt],
            root: PdfReference::to(1, 0),
            info: null,
            encrypt: PdfReference::to(2, 0),
            documentId: 'abcdef0123456789abcdef0123456789',
            transformer: $transformer,
        );

        self::assertStringStartsWith("%PDF-1.7\n%\xE2\xE3\xCF\xD3\n", $bytes);
        self::assertStringEndsWith("%%EOF\n", $bytes);
        self::assertStringContainsString('/Encrypt 2 0 R', $bytes);
        self::assertStringContainsString('/ID [<ABCDEF0123456789ABCDEF0123456789>', $bytes);
    }

    public function testInfoRefIncludedWhenProvided(): void
    {
        $catalog = IndirectObject::of(1, 0, Dictionary::empty()->withEntry(Name::of('Type'), Name::of('Catalog')));
        $info = IndirectObject::of(2, 0, Dictionary::empty()->withEntry(Name::of('Title'), Name::of('X')));
        $encrypt = IndirectObject::of(3, 0, Dictionary::empty()->withEntry(Name::of('Filter'), Name::of('Standard')));
        $bytes = (new EncryptedPdfWriter())->write(
            objects: [$catalog, $info, $encrypt],
            root: PdfReference::to(1, 0),
            info: PdfReference::to(2, 0),
            encrypt: PdfReference::to(3, 0),
            documentId: 'deadbeefdeadbeefdeadbeefdeadbeef',
            transformer: new ObjectTransformer(
                cipher: new Cipher(),
                fileKey: str_repeat("\x01", 32),
                randomSource: fn (int $n) => str_repeat("\x00", $n),
                encryptObjectNumber: 3,
                metadataObjectNumber: null,
                encryptMetadata: false,
            ),
        );
        self::assertStringContainsString('/Info 2 0 R', $bytes);
    }
}
