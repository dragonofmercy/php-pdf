<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Encryption\Reader;

use DragonOfMercy\PhpPdf\Document\MetadataStream;
use DragonOfMercy\PhpPdf\Encryption\Cipher;
use DragonOfMercy\PhpPdf\Encryption\EncryptionKey;
use DragonOfMercy\PhpPdf\Encryption\PasswordHash;
use DragonOfMercy\PhpPdf\Encryption\Reader\AesCbcDecryptor;
use DragonOfMercy\PhpPdf\Encryption\Reader\EncryptionParams;
use DragonOfMercy\PhpPdf\Encryption\Reader\IncrementalObjectEncryptor;
use DragonOfMercy\PhpPdf\Encryption\Reader\Rc4Cipher;
use DragonOfMercy\PhpPdf\Encryption\Reader\StandardSecurityHandler;
use DragonOfMercy\PhpPdf\Encryption\Reader\StreamCipher;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Writer\Object\CompressedStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use DragonOfMercy\PhpPdf\Writer\Object\Stream;
use DragonOfMercy\PhpPdf\Writer\Object\TextString;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip anchor for the encrypt-side object walker: encrypting the new
 * objects of an edited revision, then reversing each ciphertext with the reader
 * primitives (objectKey + Rc4Cipher / AesCbcDecryptor), must recover the
 * original plaintext for RC4, AES-128 and AES-256.
 */
final class IncrementalObjectEncryptorTest extends TestCase
{
    /** Deterministic IV/random source: a single repeated byte, length-aware. */
    private function ivSource(): \Closure
    {
        return static fn (int $n): string => str_repeat("\x09", $n);
    }

    private function aesv3Handler(): StandardSecurityHandler
    {
        $ph = new PasswordHash();
        $seed = 12345;
        $rand = function (int $n) use (&$seed): string {
            $s = '';
            for ($i = 0; $i < $n; $i++) {
                $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;
                $s .= chr($seed & 0xFF);
            }
            return $s;
        };
        $key = new EncryptionKey('', 'owner', -4, true, $rand, $ph, new Cipher());
        $params = EncryptionParams::forAesv3($key->o(), $key->u(), $key->oe(), $key->ue(), -4, true, 'ID0');
        return (new StandardSecurityHandler($params, $ph))->authenticate(null);
    }

    private const string PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    /** Build a self-consistent legacy (RC4 / AES-128) handler for the empty user password. */
    private function legacyHandler(int $r, int $keyLen, StreamCipher $cipher): StandardSecurityHandler
    {
        $p = -4;
        $idFirst = 'ID0_sixteen_byte';
        $pad = fn (string $pw): string => substr($pw . self::PAD, 0, 32);

        $rc4key = md5($pad('owner'), true);
        if ($r >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $rc4key = md5(substr($rc4key, 0, $keyLen), true);
            }
        }
        $rc4key = substr($rc4key, 0, $keyLen);
        $o = Rc4Cipher::apply($rc4key, $pad(''));
        if ($r >= 3) {
            for ($i = 1; $i <= 19; $i++) {
                $rk = '';
                foreach (str_split($rc4key) as $b) {
                    $rk .= chr((ord($b) ^ $i) & 0xFF);
                }
                $o = Rc4Cipher::apply($rk, $o);
            }
        }

        $input = $pad('') . $o . pack('V', $p & 0xFFFFFFFF) . $idFirst;
        if ($r >= 4) {
            // encryptMetadata true: no 0xFFFFFFFF suffix.
        }
        $fileKey = md5($input, true);
        if ($r >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $fileKey = md5(substr($fileKey, 0, $keyLen), true);
            }
        }
        $fileKey = substr($fileKey, 0, $keyLen);

        if ($r === 2) {
            $u = Rc4Cipher::apply($fileKey, self::PAD);
        } else {
            $x = Rc4Cipher::apply($fileKey, md5(self::PAD . $idFirst, true));
            for ($i = 1; $i <= 19; $i++) {
                $rk = '';
                foreach (str_split($fileKey) as $b) {
                    $rk .= chr((ord($b) ^ $i) & 0xFF);
                }
                $x = Rc4Cipher::apply($rk, $x);
            }
            $u = $x . substr(self::PAD, 0, 16);
        }

        $params = EncryptionParams::forRc4($r, $o, $u, $p, $keyLen, true, $idFirst, $cipher);
        return (new StandardSecurityHandler($params, new PasswordHash()))->authenticate(null);
    }

    /** Reverse a ciphertext produced by the encryptor, using the reader primitives. */
    private function reverse(string $key, string $cipherText, StreamCipher $cipher): string
    {
        return match ($cipher) {
            StreamCipher::Rc4 => Rc4Cipher::apply($key, $cipherText),
            StreamCipher::Aesv2, StreamCipher::Aesv3 => AesCbcDecryptor::decrypt($key, $cipherText),
        };
    }

    /**
     * Build an object (num 7, gen 0) with a string and a stream, encrypt it, and
     * assert each emitted ciphertext reverses to the original plaintext.
     *
     * @param StandardSecurityHandler $handler authenticated handler
     */
    private function assertRoundTrips(StandardSecurityHandler $handler): void
    {
        $num = 7;
        $gen = 0;
        $strCipher = $handler->stringCipher();
        $stmCipher = $handler->streamCipher();
        $strKey = $handler->objectKey($num, $gen, $strCipher);
        $stmKey = $handler->objectKey($num, $gen, $stmCipher);

        $payload = Dictionary::empty()
            ->withEntry(Name::of('Title'), PdfString::of('hello string'))
            ->withEntry(Name::of('Body'), Stream::of('raw stream content bytes'));
        $obj = IndirectObject::of($num, $gen, $payload);

        $enc = new IncrementalObjectEncryptor($handler, $this->ivSource(), true);
        $encrypted = $enc->encrypt($obj);

        $resultDict = $encrypted->payload();
        self::assertInstanceOf(Dictionary::class, $resultDict);

        $title = $resultDict->get(Name::of('Title'));
        self::assertInstanceOf(HexString::class, $title);
        $titleBytes = hex2bin($title->hex());
        self::assertNotFalse($titleBytes);
        self::assertSame('hello string', $this->reverse($strKey, $titleBytes, $strCipher));

        $body = $resultDict->get(Name::of('Body'));
        self::assertInstanceOf(PdfObject::class, $body);
        $bodyBytes = $this->streamBytes($body);
        self::assertSame('raw stream content bytes', $this->reverse($stmKey, $bodyBytes, $stmCipher));
    }

    /** Extract the raw frozen bytes of an encrypted stream by parsing toBytes(). */
    private function streamBytes(PdfObject $stream): string
    {
        $bytes = $stream->toBytes();
        $start = strpos($bytes, "stream\n");
        self::assertNotFalse($start);
        $start += strlen("stream\n");
        $end = strrpos($bytes, "\nendstream");
        self::assertNotFalse($end);
        return substr($bytes, $start, $end - $start);
    }

    public function testAesv3RoundTrips(): void
    {
        $this->assertRoundTrips($this->aesv3Handler());
    }

    public function testAes128RoundTrips(): void
    {
        $this->assertRoundTrips($this->legacyHandler(4, 16, StreamCipher::Aesv2));
    }

    public function testRc4RoundTrips(): void
    {
        $this->assertRoundTrips($this->legacyHandler(3, 16, StreamCipher::Rc4));
    }

    public function testTextStringRoundTripsAsUtf16Be(): void
    {
        $handler = $this->aesv3Handler();
        $strKey = $handler->objectKey(7, 0, $handler->stringCipher());

        $payload = Dictionary::empty()->withEntry(Name::of('T'), TextString::of('cafe'));
        $obj = IndirectObject::of(7, 0, $payload);

        $enc = new IncrementalObjectEncryptor($handler, $this->ivSource(), true);
        $encrypted = $enc->encrypt($obj);

        $dict = $encrypted->payload();
        self::assertInstanceOf(Dictionary::class, $dict);
        $t = $dict->get(Name::of('T'));
        self::assertInstanceOf(HexString::class, $t);
        $cipherBytes = hex2bin($t->hex());
        self::assertNotFalse($cipherBytes);
        $plain = $this->reverse($strKey, $cipherBytes, $handler->stringCipher());
        self::assertSame("\xFE\xFF\x00c\x00a\x00f\x00e", $plain);
    }

    public function testCompressedStreamRoundTrips(): void
    {
        $handler = $this->aesv3Handler();
        $stmKey = $handler->objectKey(7, 0, $handler->streamCipher());

        $payload = Dictionary::empty()
            ->withEntry(Name::of('C'), CompressedStream::of('the compressed plaintext'));
        $obj = IndirectObject::of(7, 0, $payload);

        $enc = new IncrementalObjectEncryptor($handler, $this->ivSource(), true);
        $encrypted = $enc->encrypt($obj);

        $dict = $encrypted->payload();
        self::assertInstanceOf(Dictionary::class, $dict);
        $c = $dict->get(Name::of('C'));
        self::assertInstanceOf(PdfObject::class, $c);
        $cipherBytes = $this->streamBytes($c);
        $compressed = $this->reverse($stmKey, $cipherBytes, $handler->streamCipher());
        $plain = gzuncompress($compressed);
        self::assertSame('the compressed plaintext', $plain);
    }

    public function testReadStreamRoundTrips(): void
    {
        $handler = $this->aesv3Handler();
        $stmKey = $handler->objectKey(7, 0, $handler->streamCipher());

        $payload = Dictionary::empty()
            ->withEntry(Name::of('R'), new ReadStream(Dictionary::empty(), 'already encoded payload'));
        $obj = IndirectObject::of(7, 0, $payload);

        $enc = new IncrementalObjectEncryptor($handler, $this->ivSource(), true);
        $encrypted = $enc->encrypt($obj);

        $dict = $encrypted->payload();
        self::assertInstanceOf(Dictionary::class, $dict);
        $r = $dict->get(Name::of('R'));
        self::assertInstanceOf(PdfObject::class, $r);
        $cipherBytes = $this->streamBytes($r);
        self::assertSame('already encoded payload', $this->reverse($stmKey, $cipherBytes, $handler->streamCipher()));
    }

    public function testEmptyStringDoesNotEmitALoneIv(): void
    {
        $handler = $this->aesv3Handler();
        $payload = Dictionary::empty()->withEntry(Name::of('E'), PdfString::of(''));
        $obj = IndirectObject::of(7, 0, $payload);

        $enc = new IncrementalObjectEncryptor($handler, $this->ivSource(), true);
        $encrypted = $enc->encrypt($obj);

        $dict = $encrypted->payload();
        self::assertInstanceOf(Dictionary::class, $dict);
        $e = $dict->get(Name::of('E'));
        self::assertInstanceOf(HexString::class, $e);
        self::assertSame('', $e->hex());
    }

    public function testUnencryptedMetadataStreamIsReturnedUnchanged(): void
    {
        $handler = $this->aesv3Handler();
        $metadata = new MetadataStream('<xmp>cleartext</xmp>');
        $obj = IndirectObject::of(10, 0, $metadata);

        $enc = new IncrementalObjectEncryptor($handler, $this->ivSource(), false);
        $result = $enc->encrypt($obj);

        self::assertSame($obj, $result);
    }

    public function testThrowsOnUnexpectedType(): void
    {
        $handler = $this->aesv3Handler();
        $unexpected = new class implements PdfObject {
            public function toBytes(): string
            {
                return '/Unexpected';
            }
        };
        $payload = Dictionary::empty()->withEntry(Name::of('X'), $unexpected);
        $obj = IndirectObject::of(7, 0, $payload);

        $enc = new IncrementalObjectEncryptor($handler, $this->ivSource(), true);
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $enc->encrypt($obj);
    }
}
