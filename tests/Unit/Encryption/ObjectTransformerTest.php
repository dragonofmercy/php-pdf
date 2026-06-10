<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Encryption;

use DragonOfMercy\PhpPdf\Document\MetadataStream;
use DragonOfMercy\PhpPdf\Encryption\Cipher;
use DragonOfMercy\PhpPdf\Encryption\ObjectTransformer;
use DragonOfMercy\PhpPdf\Image\ImageStream;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Writer\Object\CompressedStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use DragonOfMercy\PhpPdf\Writer\Object\Stream;
use DragonOfMercy\PhpPdf\Writer\Object\TextString;
use PHPUnit\Framework\TestCase;

final class ObjectTransformerTest extends TestCase
{
    private function make(int $encryptObjectNumber = 99, ?int $metadataObjectNumber = null): ObjectTransformer
    {
        return new ObjectTransformer(
            cipher: new Cipher(),
            fileKey: str_repeat("\x01", 32),
            randomSource: fn (int $n): string => str_repeat("\x00", $n),
            encryptObjectNumber: $encryptObjectNumber,
            metadataObjectNumber: $metadataObjectNumber,
            encryptMetadata: false,
        );
    }

    public function testPdfStringBecomesEncryptedHexString(): void
    {
        $obj = IndirectObject::of(1, 0, PdfString::of('hello'));
        $result = $this->make()->transform($obj);
        $bytes = $result->toBytes();
        self::assertSame(1, preg_match('/^1 0 obj\n<[0-9A-F]{64}>\nendobj\n$/', $bytes));
    }

    public function testNamePdfNumberAndReferenceArePassThrough(): void
    {
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Page'))
            ->withEntry(Name::of('Count'), PdfNumber::ofInt(42))
            ->withEntry(Name::of('Parent'), PdfReference::to(2, 0));
        $result = $this->make()->transform(IndirectObject::of(3, 0, $dict));
        $bytes = $result->toBytes();
        self::assertStringContainsString('/Type /Page', $bytes);
        self::assertStringContainsString('/Count 42', $bytes);
        self::assertStringContainsString('/Parent 2 0 R', $bytes);
    }

    public function testDictionaryRecursesIntoNestedValues(): void
    {
        $inner = Dictionary::empty()->withEntry(Name::of('Title'), TextString::of('Hi'));
        $outer = Dictionary::empty()->withEntry(Name::of('Info'), $inner);
        $result = $this->make()->transform(IndirectObject::of(1, 0, $outer));
        $bytes = $result->toBytes();
        self::assertSame(1, preg_match('/\/Title <[0-9A-F]+>/', $bytes));
    }

    public function testArrayRecursesIntoElements(): void
    {
        $arr = PdfArray::of(PdfString::of('a'), PdfString::of('b'));
        $result = $this->make()->transform(IndirectObject::of(1, 0, $arr));
        $bytes = $result->toBytes();
        self::assertSame(1, preg_match('/\[<[0-9A-F]+> <[0-9A-F]+>\]/', $bytes));
    }

    public function testEncryptObjectIsPassThrough(): void
    {
        $payload = Dictionary::empty()->withEntry(Name::of('U'), HexString::of('ABCDEF'));
        $obj = IndirectObject::of(99, 0, $payload);
        $result = $this->make(encryptObjectNumber: 99)->transform($obj);
        self::assertStringContainsString('/U <ABCDEF>', $result->toBytes());
    }

    public function testStreamContentIsEncrypted(): void
    {
        $result = $this->make()->transform(IndirectObject::of(1, 0, Stream::of('hello')));
        $bytes = $result->toBytes();
        self::assertStringContainsString('/Length 32', $bytes);
        self::assertStringContainsString("stream\n", $bytes);
        self::assertStringContainsString("\nendstream", $bytes);
    }

    public function testCompressedStreamContentIsEncrypted(): void
    {
        $result = $this->make()->transform(IndirectObject::of(1, 0, CompressedStream::of('hello')));
        $bytes = $result->toBytes();
        self::assertStringContainsString('/Filter /FlateDecode', $bytes);
    }

    public function testImageStreamBodyIsEncryptedAndDictionaryPreserved(): void
    {
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('XObject'))
            ->withEntry(Name::of('Subtype'), Name::of('Image'))
            ->withEntry(Name::of('Width'), PdfNumber::ofInt(2))
            ->withEntry(Name::of('Height'), PdfNumber::ofInt(2))
            ->withEntry(Name::of('Filter'), Name::of('DCTDecode'));
        $body = "RAW_JPEG_BYTES";
        $obj = IndirectObject::of(7, 0, new ImageStream($dict, $body));

        $bytes = $this->make()->transform($obj)->toBytes();

        self::assertStringContainsString('/Type /XObject', $bytes);
        self::assertStringContainsString('/Subtype /Image', $bytes);
        self::assertStringContainsString('/Filter /DCTDecode', $bytes);
        self::assertStringNotContainsString($body, $bytes, 'image body must not appear unencrypted');
        // AES-CBC: 16-byte IV + PKCS7-padded ciphertext (a 14-byte body pads to 16).
        self::assertStringContainsString('/Length 32', $bytes);
    }

    public function testReadStreamRawDataIsEncryptedAndDictionaryPreserved(): void
    {
        // imported (template) streams arrive as Reader\ReadStream with their raw,
        // still-encoded payload; encryption must cipher that payload and keep the
        // dictionary (recursing into any strings it holds).
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('XObject'))
            ->withEntry(Name::of('Subtype'), Name::of('Form'))
            ->withEntry(Name::of('Note'), PdfString::of('keep me'));
        $body = 'q Q imported content';
        $obj = IndirectObject::of(8, 0, new ReadStream($dict, $body));

        $bytes = $this->make()->transform($obj)->toBytes();

        self::assertStringContainsString('/Type /XObject', $bytes);
        self::assertStringContainsString('/Subtype /Form', $bytes);
        self::assertStringNotContainsString($body, $bytes, 'template body must not appear unencrypted');
        self::assertStringNotContainsString('keep me', $bytes, 'dictionary strings must be encrypted too');
        self::assertSame(1, preg_match('/\/Note <[0-9A-F]+>/', $bytes));
    }

    public function testMetadataStreamNotEncryptedWhenFlagFalse(): void
    {
        $xmp = 'XMP content here';
        $obj = IndirectObject::of(4, 0, new MetadataStream($xmp));
        $transformer = new ObjectTransformer(
            cipher: new Cipher(),
            fileKey: str_repeat("\x01", 32),
            randomSource: fn (int $n) => str_repeat("\x00", $n),
            encryptObjectNumber: 99,
            metadataObjectNumber: 4,
            encryptMetadata: false,
        );
        $bytes = $transformer->transform($obj)->toBytes();
        self::assertStringContainsString($xmp, $bytes);
        self::assertStringContainsString('/Filter /Crypt', $bytes);
        self::assertStringContainsString('/Name /Identity', $bytes);
    }
}
