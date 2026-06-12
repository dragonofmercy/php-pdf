<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Encryption\Reader;

use DragonOfMercy\PhpPdf\Encryption\Cipher;
use DragonOfMercy\PhpPdf\Encryption\EncryptionKey;
use DragonOfMercy\PhpPdf\Encryption\PasswordHash;
use DragonOfMercy\PhpPdf\Encryption\Reader\EncryptionParams;
use DragonOfMercy\PhpPdf\Encryption\Reader\ObjectDecryptor;
use DragonOfMercy\PhpPdf\Encryption\Reader\Rc4Cipher;
use DragonOfMercy\PhpPdf\Encryption\Reader\StandardSecurityHandler;
use DragonOfMercy\PhpPdf\Encryption\Reader\StreamCipher;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfBoolean;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use PHPUnit\Framework\TestCase;

final class ObjectDecryptorTest extends TestCase
{
    /** Build an authenticated AESV3 handler (file key recovered). */
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

    /** AES-256-CBC encrypt with a random IV prepended (mirrors the writer side). */
    private function aesEncrypt(string $key, string $plaintext): string
    {
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        self::assertNotFalse($ciphertext);
        return $iv . $ciphertext;
    }

    public function testDecryptsAesv3StringByObjectKey(): void
    {
        $handler = $this->aesv3Handler();
        $key = $handler->objectKey(7, 0, StreamCipher::Aesv3);
        $cipher = $this->aesEncrypt($key, 'hello');

        $decryptor = new ObjectDecryptor($handler, -1, null, true);
        $result = $decryptor->decrypt(PdfString::of($cipher), 7, 0);

        self::assertInstanceOf(PdfString::class, $result);
        self::assertSame('hello', $result->value());
    }

    public function testAesv3ObjectKeyIsTheFileKey(): void
    {
        $handler = $this->aesv3Handler();
        self::assertSame($handler->fileKey(), $handler->objectKey(42, 3, StreamCipher::Aesv3));
    }

    public function testDecryptsAesv3StreamBody(): void
    {
        $handler = $this->aesv3Handler();
        $key = $handler->objectKey(9, 0, StreamCipher::Aesv3);
        $body = $this->aesEncrypt($key, 'stream body bytes');
        $stream = new ReadStream(Dictionary::empty(), $body);

        $decryptor = new ObjectDecryptor($handler, -1, null, true);
        $result = $decryptor->decrypt($stream, 9, 0);

        self::assertInstanceOf(ReadStream::class, $result);
        self::assertSame('stream body bytes', $result->rawData());
    }

    public function testDecryptsNestedDictionaryAndArrayOfStrings(): void
    {
        $handler = $this->aesv3Handler();
        $key = $handler->objectKey(5, 0, StreamCipher::Aesv3);

        $inner = Dictionary::empty()
            ->withEntry(Name::of('A'), PdfString::of($this->aesEncrypt($key, 'alpha')))
            ->withEntry(Name::of('List'), PdfArray::of(
                PdfString::of($this->aesEncrypt($key, 'one')),
                HexString::of(strtoupper(bin2hex($this->aesEncrypt($key, 'two')))),
            ));
        $outer = Dictionary::empty()
            ->withEntry(Name::of('Inner'), $inner)
            ->withEntry(Name::of('N'), PdfNumber::ofInt(3));

        $decryptor = new ObjectDecryptor($handler, -1, null, true);
        $result = $decryptor->decrypt($outer, 5, 0);

        self::assertInstanceOf(Dictionary::class, $result);
        $resultInner = $result->get(Name::of('Inner'));
        self::assertInstanceOf(Dictionary::class, $resultInner);

        $a = $resultInner->get(Name::of('A'));
        self::assertInstanceOf(PdfString::class, $a);
        self::assertSame('alpha', $a->value());

        $list = $resultInner->get(Name::of('List'));
        self::assertInstanceOf(PdfArray::class, $list);
        $elements = $list->elements();
        self::assertInstanceOf(PdfString::class, $elements[0]);
        self::assertSame('one', $elements[0]->value());
        self::assertInstanceOf(HexString::class, $elements[1]);
        $hexBytes = hex2bin($elements[1]->hex());
        self::assertSame('two', $hexBytes);

        // Number preserved unchanged.
        $n = $result->get(Name::of('N'));
        self::assertInstanceOf(PdfNumber::class, $n);
        self::assertSame(3, $n->value());
    }

    public function testScalarLeavesArePassedThroughUnchanged(): void
    {
        $handler = $this->aesv3Handler();
        $decryptor = new ObjectDecryptor($handler, -1, null, true);

        foreach ([Name::of('Type'), PdfNumber::ofInt(42), PdfBoolean::true(), PdfNull::instance()] as $scalar) {
            self::assertSame($scalar, $decryptor->decrypt($scalar, 7, 0));
        }
    }

    public function testEncryptObjectIsReturnedUnchanged(): void
    {
        $handler = $this->aesv3Handler();
        $decryptor = new ObjectDecryptor($handler, 4, null, true);
        $original = PdfString::of('this would not decrypt');
        $result = $decryptor->decrypt($original, 4, 0);
        self::assertSame($original, $result);
    }

    public function testUnencryptedMetadataStreamIsReturnedUnchanged(): void
    {
        $handler = $this->aesv3Handler();
        $decryptor = new ObjectDecryptor($handler, -1, 10, false);
        $stream = new ReadStream(Dictionary::empty(), 'cleartext xmp');
        $result = $decryptor->decrypt($stream, 10, 0);
        self::assertSame($stream, $result);
    }

    public function testDecryptsRc4String(): void
    {
        // Build an RC4 handler self-consistently and authenticate the empty
        // password, then RC4-encrypt a string under the derived object key.
        $params = $this->buildRc4Params();
        $handler = (new StandardSecurityHandler($params, new PasswordHash()))->authenticate(null);

        $key = $handler->objectKey(11, 0, StreamCipher::Rc4);
        $cipher = Rc4Cipher::apply($key, 'rc4 secret');

        $decryptor = new ObjectDecryptor($handler, -1, null, true);
        $result = $decryptor->decrypt(PdfString::of($cipher), 11, 0);

        self::assertInstanceOf(PdfString::class, $result);
        self::assertSame('rc4 secret', $result->value());
    }

    private const string PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    /** Build self-consistent R3/RC4 params for the empty user password (16-byte key). */
    private function buildRc4Params(): EncryptionParams
    {
        $r = 3;
        $keyLen = 16;
        $p = -4;
        $idFirst = 'ID0_sixteen_byte';
        $pad = fn (string $pw): string => substr($pw . self::PAD, 0, 32);

        // Algorithm 3: /O from owner + user (both with empty user password here).
        $rc4key = md5($pad('owner'), true);
        for ($i = 0; $i < 50; $i++) {
            $rc4key = md5(substr($rc4key, 0, $keyLen), true);
        }
        $rc4key = substr($rc4key, 0, $keyLen);
        $o = Rc4Cipher::apply($rc4key, $pad(''));
        for ($i = 1; $i <= 19; $i++) {
            $rk = '';
            foreach (str_split($rc4key) as $b) {
                $rk .= chr((ord($b) ^ $i) & 0xFF);
            }
            $o = Rc4Cipher::apply($rk, $o);
        }

        // Algorithm 2: file key from the empty user password.
        $input = $pad('') . $o . pack('V', $p & 0xFFFFFFFF) . $idFirst;
        $fileKey = md5($input, true);
        for ($i = 0; $i < 50; $i++) {
            $fileKey = md5(substr($fileKey, 0, $keyLen), true);
        }
        $fileKey = substr($fileKey, 0, $keyLen);

        // Algorithm 5: /U from the file key (R3 -> 32-byte stored value).
        $x = Rc4Cipher::apply($fileKey, md5(self::PAD . $idFirst, true));
        for ($i = 1; $i <= 19; $i++) {
            $rk = '';
            foreach (str_split($fileKey) as $b) {
                $rk .= chr((ord($b) ^ $i) & 0xFF);
            }
            $x = Rc4Cipher::apply($rk, $x);
        }
        $u = $x . substr(self::PAD, 0, 16);

        return EncryptionParams::forRc4($r, $o, $u, $p, $keyLen, true, $idFirst, StreamCipher::Rc4);
    }
}
