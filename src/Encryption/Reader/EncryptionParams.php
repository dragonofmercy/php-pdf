<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Encryption\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfBoolean;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;

/**
 * Parsed view of a Standard-security-handler /Encrypt dictionary plus the
 * trailer /ID, as the typed input the reader's security handler consumes.
 * Supports the encryption versions this library can read: V1/V2 (RC4),
 * V4 (RC4 or AES-128 via crypt filters) and V5/R6 (AES-256).
 *
 * @internal
 */
final readonly class EncryptionParams
{
    public function __construct(
        public int $v,
        public int $r,
        public string $o,
        public string $u,
        public string $oe,
        public string $ue,
        public int $p,
        public int $keyLengthBytes,
        public bool $encryptMetadata,
        public string $idFirst,
        public StreamCipher $stmCipher,
        public StreamCipher $strCipher,
    ) {}

    /**
     * Convenience constructor for V5/R6 AES-256, used by the security handler
     * tests and key-derivation tasks.
     */
    public static function forAesv3(
        string $o,
        string $u,
        string $oe,
        string $ue,
        int $p,
        bool $encryptMetadata,
        string $idFirst,
    ): self {
        return new self(
            v: 5,
            r: 6,
            o: $o,
            u: $u,
            oe: $oe,
            ue: $ue,
            p: $p,
            keyLengthBytes: 32,
            encryptMetadata: $encryptMetadata,
            idFirst: $idFirst,
            stmCipher: StreamCipher::Aesv3,
            strCipher: StreamCipher::Aesv3,
        );
    }

    /**
     * Parses a resolved /Encrypt dictionary plus the document trailer into
     * typed parameters. $resolve dereferences any PdfReference values.
     *
     * @param callable(PdfObject): PdfObject $resolve
     */
    public static function fromTrailer(Dictionary $encryptDict, Dictionary $trailer, callable $resolve): self
    {
        $filter = self::resolved($encryptDict->get(Name::of('Filter')), $resolve);
        if (!$filter instanceof Name || $filter->value() !== 'Standard') {
            $got = $filter instanceof Name ? '/' . $filter->value() : 'a non-name value';
            throw new PdfException('Unsupported encryption: /Filter must be /Standard, got ' . $got);
        }

        $v = self::readInt($encryptDict->get(Name::of('V')), $resolve, 0);
        $r = self::readInt($encryptDict->get(Name::of('R')), $resolve, 0);
        $p = self::readInt($encryptDict->get(Name::of('P')), $resolve, 0);
        $lengthBits = self::readInt($encryptDict->get(Name::of('Length')), $resolve, 40);
        $encryptMetadata = self::readBool($encryptDict->get(Name::of('EncryptMetadata')), $resolve, true);

        $o = self::readBytes($encryptDict->get(Name::of('O')), $resolve);
        $u = self::readBytes($encryptDict->get(Name::of('U')), $resolve);
        $oe = self::readBytes($encryptDict->get(Name::of('OE')), $resolve);
        $ue = self::readBytes($encryptDict->get(Name::of('UE')), $resolve);

        $idFirst = self::readIdFirst($trailer, $resolve);

        [$stmCipher, $strCipher, $keyLengthBytes] = self::resolveCiphers($v, $lengthBits, $encryptDict, $resolve);

        return new self(
            v: $v,
            r: $r,
            o: $o,
            u: $u,
            oe: $oe,
            ue: $ue,
            p: $p,
            keyLengthBytes: $keyLengthBytes,
            encryptMetadata: $encryptMetadata,
            idFirst: $idFirst,
            stmCipher: $stmCipher,
            strCipher: $strCipher,
        );
    }

    /**
     * @param callable(PdfObject): PdfObject $resolve
     * @return array{StreamCipher, StreamCipher, int}
     */
    private static function resolveCiphers(int $v, int $lengthBits, Dictionary $encryptDict, callable $resolve): array
    {
        return match ($v) {
            1 => [StreamCipher::Rc4, StreamCipher::Rc4, 5],
            2 => [StreamCipher::Rc4, StreamCipher::Rc4, intdiv($lengthBits, 8)],
            4 => self::resolveV4Ciphers($lengthBits, $encryptDict, $resolve),
            5 => [StreamCipher::Aesv3, StreamCipher::Aesv3, 32],
            default => throw new PdfException('Unsupported encryption version V=' . $v),
        };
    }

    /**
     * Resolves the StmF / StrF crypt filters for a V4 /Encrypt dictionary.
     *
     * @param callable(PdfObject): PdfObject $resolve
     * @return array{StreamCipher, StreamCipher, int}
     */
    private static function resolveV4Ciphers(int $lengthBits, Dictionary $encryptDict, callable $resolve): array
    {
        $cf = self::resolved($encryptDict->get(Name::of('CF')), $resolve);
        $cf = $cf instanceof Dictionary ? $cf : Dictionary::empty();

        $stmName = self::filterName($encryptDict->get(Name::of('StmF')), $resolve);
        $strName = self::filterName($encryptDict->get(Name::of('StrF')), $resolve);

        [$stmCipher, $stmKeyBytes] = self::cryptFilterCipher($stmName, $cf, $lengthBits, $resolve);
        [$strCipher, $strKeyBytes] = self::cryptFilterCipher($strName, $cf, $lengthBits, $resolve);

        // The stream key length governs the file key; per the spec StmF and StrF
        // share one file encryption key, so use the stream-derived length.
        return [$stmCipher, $strCipher, $stmKeyBytes];
    }

    /**
     * @param callable(PdfObject): PdfObject $resolve
     */
    private static function filterName(?PdfObject $object, callable $resolve): string
    {
        $name = self::resolved($object, $resolve);
        return $name instanceof Name ? $name->value() : 'Identity';
    }

    /**
     * Maps a V4 crypt-filter name to its cipher and key length in bytes.
     *
     * @param callable(PdfObject): PdfObject $resolve
     * @return array{StreamCipher, int}
     */
    private static function cryptFilterCipher(string $filterName, Dictionary $cf, int $lengthBits, callable $resolve): array
    {
        if ($filterName === 'Identity') {
            throw new PdfException('Identity crypt filter not supported');
        }

        $filter = self::resolved($cf->get(Name::of($filterName)), $resolve);
        if (!$filter instanceof Dictionary) {
            throw new PdfException('Crypt filter /' . $filterName . ' not found in /CF');
        }

        $cfm = self::resolved($filter->get(Name::of('CFM')), $resolve);
        $cfmValue = $cfm instanceof Name ? $cfm->value() : '';

        // In a V4 crypt-filter dictionary /Length is in BYTES (ISO 32000-1),
        // unlike the top-level /Length (V<=2) which is in BITS.
        $cfLengthBytes = self::readOptionalInt($filter->get(Name::of('Length')), $resolve);

        return match ($cfmValue) {
            'V2' => [StreamCipher::Rc4, $cfLengthBytes ?? intdiv($lengthBits, 8)],
            'AESV2' => [StreamCipher::Aesv2, $cfLengthBytes ?? 16],
            default => throw new PdfException('Unsupported crypt filter method /CFM /' . ($cfmValue === '' ? '(missing)' : $cfmValue)),
        };
    }

    /**
     * Reads a string-valued entry that may be a PdfString or a HexString.
     *
     * @param callable(PdfObject): PdfObject $resolve
     */
    private static function readBytes(?PdfObject $object, callable $resolve): string
    {
        $value = self::resolved($object, $resolve);
        if ($value instanceof PdfString) {
            return $value->value();
        }
        if ($value instanceof HexString) {
            $binary = hex2bin($value->hex());
            return $binary === false ? '' : $binary;
        }
        return '';
    }

    /**
     * @param callable(PdfObject): PdfObject $resolve
     */
    private static function readIdFirst(Dictionary $trailer, callable $resolve): string
    {
        $id = self::resolved($trailer->get(Name::of('ID')), $resolve);
        if (!$id instanceof PdfArray) {
            return '';
        }
        $elements = $id->elements();
        if ($elements === []) {
            return '';
        }
        return self::readBytes($elements[0], $resolve);
    }

    /**
     * @param callable(PdfObject): PdfObject $resolve
     */
    private static function readInt(?PdfObject $object, callable $resolve, int $default): int
    {
        return self::readOptionalInt($object, $resolve) ?? $default;
    }

    /**
     * @param callable(PdfObject): PdfObject $resolve
     */
    private static function readOptionalInt(?PdfObject $object, callable $resolve): ?int
    {
        $value = self::resolved($object, $resolve);
        if ($value instanceof PdfNumber) {
            return (int) $value->value();
        }
        return null;
    }

    /**
     * @param callable(PdfObject): PdfObject $resolve
     */
    private static function readBool(?PdfObject $object, callable $resolve, bool $default): bool
    {
        $value = self::resolved($object, $resolve);
        if ($value instanceof PdfBoolean) {
            return $value->value();
        }
        return $default;
    }

    /**
     * @param callable(PdfObject): PdfObject $resolve
     */
    private static function resolved(?PdfObject $object, callable $resolve): ?PdfObject
    {
        if ($object === null) {
            return null;
        }
        return $resolve($object);
    }
}
