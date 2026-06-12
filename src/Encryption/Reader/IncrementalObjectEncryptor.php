<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Encryption\Reader;

use DragonOfMercy\PhpPdf\Document\MetadataStream;
use DragonOfMercy\PhpPdf\Encryption\EncryptedStreamBytes;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Image\ImageStream;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Writer\Object\CompressedStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfBoolean;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use DragonOfMercy\PhpPdf\Writer\Object\Stream;
use DragonOfMercy\PhpPdf\Writer\Object\TextString;

/**
 * Encrypts every string and stream inside a NEW object of an edited encrypted
 * PDF's incremental revision, keyed by its (object number, generation) per
 * ISO 32000-1 Algorithm 1. The encrypt-side mirror of {@see ObjectDecryptor}:
 * it walks the writer-side PdfObject variants the editor's emitters produce
 * (PdfString, TextString, HexString, Dictionary, PdfArray, and the stream types
 * Stream, ReadStream, CompressedStream, ImageStream, MetadataStream) and applies
 * the cipher.
 *
 * Unlike the generation-side {@see \DragonOfMercy\PhpPdf\Encryption\ObjectTransformer}
 * (AES-256 only, file key used directly), this derives a per-object key via
 * {@see StandardSecurityHandler::objectKey()} and dispatches RC4 / AES-128 /
 * AES-256, so it can re-encrypt revisions of legacy RC4 and AES-128 files too.
 *
 * Per-object, not per-nesting: a string nested any number of levels deep inside
 * one object uses that object's key, so the key is derived once in encrypt() and
 * threaded down the recursion.
 *
 * @internal
 */
final readonly class IncrementalObjectEncryptor
{
    /**
     * @param \Closure(int): string $ivSource random-bytes source (e.g. random_bytes)
     */
    public function __construct(
        private StandardSecurityHandler $handler,
        private \Closure $ivSource,
        private bool $encryptMetadata,
    ) {}

    /**
     * Encrypt every string and stream inside this object, keyed by
     * (objectNumber, generation). An unencrypted /Metadata stream
     * (encryptMetadata = false) is returned unchanged, so it stays readable in
     * the clear like the rest of the file expects.
     */
    public function encrypt(IndirectObject $obj): IndirectObject
    {
        if (!$this->encryptMetadata && $this->isMetadataStream($obj->payload())) {
            return $obj;
        }

        $strCipher = $this->handler->stringCipher();
        $stmCipher = $this->handler->streamCipher();
        $strKey = $this->handler->objectKey($obj->objectNumber, $obj->generation, $strCipher);
        // The string and stream crypt filters are the same for every scheme except a
        // pathological mixed-cipher V4 file, so reuse the one key derivation in the
        // common case and only re-derive when the ciphers genuinely differ.
        $stmKey = $stmCipher === $strCipher
            ? $strKey
            : $this->handler->objectKey($obj->objectNumber, $obj->generation, $stmCipher);

        return IndirectObject::of(
            $obj->objectNumber,
            $obj->generation,
            $this->encryptValue($obj->payload(), $strKey, $stmKey),
        );
    }

    /**
     * True when the payload is a /Metadata stream: the writer-side MetadataStream
     * VO, or a stream parsed back from the source whose dict /Type is /Metadata.
     */
    private function isMetadataStream(PdfObject $value): bool
    {
        if ($value instanceof MetadataStream) {
            return true;
        }
        if ($value instanceof ReadStream) {
            $type = $value->dict->get(Name::of('Type'));
            return $type instanceof Name && $type->value() === 'Metadata';
        }
        return false;
    }

    /**
     * Recursive core: walk $value producing a new object with encrypted leaves.
     * The same $strKey / $stmKey are threaded through every level (per-object
     * keying), so nested dictionaries and arrays do NOT re-derive a key. Strings
     * use the STRING key+cipher, stream bodies use the STREAM key+cipher.
     */
    private function encryptValue(PdfObject $value, string $strKey, string $stmKey): PdfObject
    {
        $strCipher = $this->handler->stringCipher();
        $stmCipher = $this->handler->streamCipher();

        return match (true) {
            $value instanceof PdfString => $this->encryptString($value->value(), $strKey, $strCipher),
            $value instanceof TextString => $this->encryptString(
                "\xFE\xFF" . mb_convert_encoding($value->value(), 'UTF-16BE', 'UTF-8'),
                $strKey,
                $strCipher,
            ),
            $value instanceof HexString => $this->encryptString(self::decodeHex($value->hex()), $strKey, $strCipher),
            $value instanceof Dictionary => $this->encryptDictionary($value, $strKey, $stmKey),
            $value instanceof PdfArray => $this->encryptArray($value, $strKey, $stmKey),
            $value instanceof Stream => $this->encryptStreamBody(Dictionary::empty(), $value->content(), $stmKey, $stmCipher),
            $value instanceof ReadStream => $this->encryptStreamBody(
                $this->encryptDictionary($value->dict, $strKey, $stmKey),
                $value->rawData(),
                $stmKey,
                $stmCipher,
            ),
            $value instanceof CompressedStream => $this->encryptStreamBody(
                $value->streamDict(),
                $value->compressedContent(),
                $stmKey,
                $stmCipher,
            ),
            $value instanceof ImageStream => $this->encryptStreamBody(
                $value->dictionary(),
                $value->body(),
                $stmKey,
                $stmCipher,
            ),
            $value instanceof MetadataStream => $this->encryptStreamBody(
                Dictionary::empty(),
                $value->xmpContent(),
                $stmKey,
                $stmCipher,
            )->withType('Metadata')->withSubtype('XML'),
            // Scalar leaves that carry no encrypted bytes: pass through unchanged.
            $value instanceof Name,
            $value instanceof PdfNumber,
            $value instanceof PdfReference,
            $value instanceof PdfBoolean,
            $value instanceof PdfNull => $value,
            // Any other type would silently drop or mis-encrypt bytes: fail loudly.
            default => throw new PdfException(
                'IncrementalObjectEncryptor: unexpected object type ' . $value::class . ' (cannot safely encrypt)',
            ),
        };
    }

    private function encryptDictionary(Dictionary $dict, string $strKey, string $stmKey): Dictionary
    {
        $result = Dictionary::empty();
        foreach ($dict->entries() as [$name, $value]) {
            $result = $result->withEntry($name, $this->encryptValue($value, $strKey, $stmKey));
        }
        return $result;
    }

    private function encryptArray(PdfArray $array, string $strKey, string $stmKey): PdfArray
    {
        $elements = array_map(
            fn (PdfObject $el): PdfObject => $this->encryptValue($el, $strKey, $stmKey),
            $array->elements(),
        );
        return PdfArray::of(...$elements);
    }

    private function encryptString(string $data, string $key, StreamCipher $cipher): HexString
    {
        return HexString::of(bin2hex($this->encryptBytes($data, $key, $cipher)));
    }

    private function encryptStreamBody(Dictionary $dict, string $content, string $stmKey, StreamCipher $cipher): EncryptedStreamBytes
    {
        return new EncryptedStreamBytes($dict, $this->encryptBytes($content, $stmKey, $cipher));
    }

    private function encryptBytes(string $data, string $key, StreamCipher $cipher): string
    {
        if ($data === '') {
            return '';
        }

        return match ($cipher) {
            StreamCipher::Rc4 => Rc4Cipher::apply($key, $data),
            StreamCipher::Aesv2, StreamCipher::Aesv3 => AesCbcEncryptor::encrypt($key, $data, $this->ivSource),
        };
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
