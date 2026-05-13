<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Encryption;

use DragonOfMercy\PhpPdf\Document\MetadataStream;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Image\ImageStream;
use DragonOfMercy\PhpPdf\Writer\Object\CompressedStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use DragonOfMercy\PhpPdf\Writer\Object\Stream;
use DragonOfMercy\PhpPdf\Writer\Object\TextString;

/**
 * Recursively transforms an IndirectObject into its encrypted form per PDF 2.0 R6.
 *
 * @internal
 */
final class ObjectTransformer
{
    /** @var callable(int): string */
    private $randomSource;

    /**
     * @param callable(int): string $randomSource
     */
    public function __construct(
        private readonly Cipher $cipher,
        private readonly string $fileKey,
        callable $randomSource,
        private readonly int $encryptObjectNumber,
        private readonly ?int $metadataObjectNumber,
        private readonly bool $encryptMetadata,
    ) {
        $this->randomSource = $randomSource;
    }

    public function transform(IndirectObject $obj): IndirectObject
    {
        if ($obj->objectNumber === $this->encryptObjectNumber) {
            return $obj;
        }

        if (
            $obj->objectNumber === $this->metadataObjectNumber
            && !$this->encryptMetadata
            && $obj->payload() instanceof MetadataStream
        ) {
            return $this->wrapMetadataUnencrypted($obj);
        }

        return IndirectObject::of(
            $obj->objectNumber,
            $obj->generation,
            $this->transformValue($obj->payload()),
        );
    }

    private function transformValue(PdfObject $value): PdfObject
    {
        return match (true) {
            $value instanceof Name, $value instanceof PdfNumber, $value instanceof PdfReference => $value,
            $value instanceof PdfString => $this->encryptBytes($value->value()),
            $value instanceof TextString => $this->encryptBytes("\xFE\xFF" . mb_convert_encoding($value->value(), 'UTF-16BE', 'UTF-8')),
            $value instanceof HexString => $this->encryptBytes(self::decodeHex($value->hex())),
            $value instanceof Dictionary => $this->transformDictionary($value),
            $value instanceof PdfArray => $this->transformArray($value),
            $value instanceof Stream => $this->encryptRawStream($value->content()),
            $value instanceof CompressedStream => $this->encryptCompressedStream($value),
            $value instanceof ImageStream => $this->encryptImageStream($value),
            $value instanceof MetadataStream => $this->encryptRawStream($value->xmpContent())
                                                    ->withType('Metadata')
                                                    ->withSubtype('XML'),
            default => $value,
        };
    }

    private function transformDictionary(Dictionary $dict): Dictionary
    {
        $result = Dictionary::empty();
        foreach ($dict->entries() as [$name, $value]) {
            $result = $result->withEntry($name, $this->transformValue($value));
        }
        return $result;
    }

    private function transformArray(PdfArray $array): PdfArray
    {
        $elements = array_map(
            fn (PdfObject $el): PdfObject => $this->transformValue($el),
            $array->elements(),
        );
        return PdfArray::of(...$elements);
    }

    private function encryptBytes(string $plaintext): HexString
    {
        $encrypted = $this->cipher->encrypt($plaintext, $this->fileKey, $this->randomSource);
        return HexString::of(bin2hex($encrypted));
    }

    private function encryptRawStream(string $content): EncryptedStreamBytes
    {
        $encrypted = $this->cipher->encrypt($content, $this->fileKey, $this->randomSource);
        return new EncryptedStreamBytes(Dictionary::empty(), $encrypted);
    }

    private function encryptCompressedStream(CompressedStream $stream): EncryptedStreamBytes
    {
        $encrypted = $this->cipher->encrypt($stream->compressedContent(), $this->fileKey, $this->randomSource);
        return new EncryptedStreamBytes($stream->streamDict(), $encrypted);
    }

    private function encryptImageStream(ImageStream $stream): EncryptedStreamBytes
    {
        $encrypted = $this->cipher->encrypt($stream->body(), $this->fileKey, $this->randomSource);
        return new EncryptedStreamBytes($stream->dictionary(), $encrypted);
    }

    private function wrapMetadataUnencrypted(IndirectObject $obj): IndirectObject
    {
        $payload = $obj->payload();
        assert($payload instanceof MetadataStream);

        $dict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Metadata'))
            ->withEntry(Name::of('Subtype'), Name::of('XML'))
            ->withEntry(Name::of('Filter'), Name::of('Crypt'))
            ->withEntry(
                Name::of('DecodeParms'),
                Dictionary::empty()
                    ->withEntry(Name::of('Type'), Name::of('CryptFilterDecodeParms'))
                    ->withEntry(Name::of('Name'), Name::of('Identity')),
            );
        return IndirectObject::of(
            $obj->objectNumber,
            $obj->generation,
            new EncryptedStreamBytes($dict, $payload->xmpContent()),
        );
    }

    private static function decodeHex(string $hex): string
    {
        $bytes = hex2bin($hex);
        if ($bytes === false) {
            throw new PdfException("Invalid hex string for encryption: {$hex}");
        }
        return $bytes;
    }
}
