<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Encryption\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfBoolean;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;

/**
 * Decrypts every string and stream inside a materialized PDF object, keyed by
 * its (object number, generation) per ISO 32000-1 Algorithm 1. Mirrors the
 * generation-side {@see \DragonOfMercy\PhpPdf\Encryption\ObjectTransformer} but
 * walks the reader's PdfObject variants (ReadStream, PdfString, HexString,
 * Dictionary, PdfArray, ...) and reverses the cipher.
 *
 * Per-object, not per-nesting: a string nested any number of levels deep inside
 * one object uses that object's key, so the key is derived once in decrypt()
 * and passed down the recursion.
 *
 * @internal
 */
final readonly class ObjectDecryptor
{
    public function __construct(
        private StandardSecurityHandler $handler,
        private int $encryptObjectNumber,
        private ?int $metadataObjectNumber,
        private bool $encryptMetadata,
    ) {}

    /**
     * Decrypt every string and stream inside this object, keyed by
     * (objectNumber, generation). Objects that must stay in the clear (the
     * /Encrypt dictionary, and an unencrypted /Metadata stream) are returned
     * unchanged.
     */
    public function decrypt(PdfObject $value, int $objectNumber, int $generation): PdfObject
    {
        if ($objectNumber === $this->encryptObjectNumber) {
            return $value;
        }

        if (
            $objectNumber === $this->metadataObjectNumber
            && !$this->encryptMetadata
            && $value instanceof ReadStream
        ) {
            return $value;
        }

        $strKey = $this->handler->objectKey($objectNumber, $generation, $this->handler->stringCipher());
        // The string and stream crypt filters are the same for every scheme except a
        // pathological mixed-cipher V4 file, so reuse the one key derivation in the
        // common case and only re-derive when the ciphers genuinely differ.
        $stmKey = $this->handler->streamCipher() === $this->handler->stringCipher()
            ? $strKey
            : $this->handler->objectKey($objectNumber, $generation, $this->handler->streamCipher());

        return $this->decryptValue($value, $strKey, $stmKey);
    }

    /**
     * Recursive core: walk $value producing a new object with decrypted leaves.
     * The same $strKey / $stmKey are threaded through every level (per-object
     * keying), so nested dictionaries and arrays do NOT re-derive a key.
     */
    private function decryptValue(PdfObject $value, string $strKey, string $stmKey): PdfObject
    {
        return match (true) {
            $value instanceof PdfString => PdfString::of(
                $this->decryptBytes($value->value(), $strKey, $this->handler->stringCipher()),
            ),
            $value instanceof HexString => HexString::of(strtoupper(bin2hex(
                $this->decryptBytes(self::decodeHex($value->hex()), $strKey, $this->handler->stringCipher()),
            ))),
            $value instanceof Dictionary => $this->decryptDictionary($value, $strKey, $stmKey),
            $value instanceof PdfArray => $this->decryptArray($value, $strKey, $stmKey),
            $value instanceof ReadStream => new ReadStream(
                $this->decryptDictionary($value->dict, $strKey, $stmKey),
                $this->decryptBytes($value->rawData(), $stmKey, $this->handler->streamCipher()),
            ),
            // Scalar leaves that carry no encrypted bytes: pass through unchanged.
            $value instanceof Name,
            $value instanceof PdfNumber,
            $value instanceof PdfReference,
            $value instanceof PdfBoolean,
            $value instanceof PdfNull => $value,
            // Any other type would silently emit ciphertext as garbage: fail loudly.
            default => throw new PdfException(
                'ObjectDecryptor: unexpected object type ' . $value::class . ' (cannot safely decrypt)',
            ),
        };
    }

    private function decryptDictionary(Dictionary $dict, string $strKey, string $stmKey): Dictionary
    {
        $result = Dictionary::empty();
        foreach ($dict->entries() as [$name, $value]) {
            $result = $result->withEntry($name, $this->decryptValue($value, $strKey, $stmKey));
        }
        return $result;
    }

    private function decryptArray(PdfArray $array, string $strKey, string $stmKey): PdfArray
    {
        $elements = array_map(
            fn (PdfObject $el): PdfObject => $this->decryptValue($el, $strKey, $stmKey),
            $array->elements(),
        );
        return PdfArray::of(...$elements);
    }

    private function decryptBytes(string $data, string $key, StreamCipher $cipher): string
    {
        if ($data === '') {
            return '';
        }

        return match ($cipher) {
            StreamCipher::Rc4 => Rc4Cipher::apply($key, $data),
            StreamCipher::Aesv2, StreamCipher::Aesv3 => AesCbcDecryptor::decrypt($key, $data),
        };
    }

    private static function decodeHex(string $hex): string
    {
        $bytes = hex2bin($hex);
        if ($bytes === false) {
            throw new PdfException("Invalid hex string for decryption: {$hex}");
        }
        return $bytes;
    }
}
