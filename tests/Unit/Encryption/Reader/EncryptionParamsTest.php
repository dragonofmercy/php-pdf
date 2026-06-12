<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Encryption\Reader;

use DragonOfMercy\PhpPdf\Encryption\Reader\EncryptionParams;
use DragonOfMercy\PhpPdf\Encryption\Reader\StreamCipher;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfBoolean;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use PHPUnit\Framework\TestCase;

final class EncryptionParamsTest extends TestCase
{
    private static function hex(string $raw): HexString
    {
        return HexString::of(strtoupper(bin2hex($raw)));
    }

    private static function trailer(string $id): Dictionary
    {
        return Dictionary::empty()->withEntry(
            Name::of('ID'),
            PdfArray::of(PdfString::of($id), PdfString::of($id)),
        );
    }

    /** @return callable(PdfObject): PdfObject */
    private static function identityResolve(): callable
    {
        return static fn (PdfObject $o): PdfObject => $o;
    }

    public function testParsesV2Rc4128(): void
    {
        $o = str_repeat("\x11", 32);
        $u = str_repeat("\x22", 32);
        $encrypt = Dictionary::empty()
            ->withEntry(Name::of('Filter'), Name::of('Standard'))
            ->withEntry(Name::of('V'), PdfNumber::ofInt(2))
            ->withEntry(Name::of('R'), PdfNumber::ofInt(3))
            ->withEntry(Name::of('Length'), PdfNumber::ofInt(128))
            ->withEntry(Name::of('P'), PdfNumber::ofInt(-44))
            ->withEntry(Name::of('O'), self::hex($o))
            ->withEntry(Name::of('U'), self::hex($u));

        $params = EncryptionParams::fromTrailer($encrypt, self::trailer('file-id-1'), self::identityResolve());

        self::assertSame(2, $params->v);
        self::assertSame(3, $params->r);
        self::assertSame(-44, $params->p);
        self::assertSame(16, $params->keyLengthBytes);
        self::assertSame(StreamCipher::Rc4, $params->stmCipher);
        self::assertSame(StreamCipher::Rc4, $params->strCipher);
        self::assertTrue($params->encryptMetadata);
        self::assertSame('file-id-1', $params->idFirst);
        self::assertSame($o, $params->o);
        self::assertSame($u, $params->u);
    }

    public function testParsesV4Aesv2(): void
    {
        $stdCf = Dictionary::empty()
            ->withEntry(Name::of('CFM'), Name::of('AESV2'))
            ->withEntry(Name::of('Length'), PdfNumber::ofInt(16));
        $cf = Dictionary::empty()->withEntry(Name::of('StdCF'), $stdCf);
        $o = str_repeat("\x33", 32);
        $u = str_repeat("\x44", 32);
        $encrypt = Dictionary::empty()
            ->withEntry(Name::of('Filter'), Name::of('Standard'))
            ->withEntry(Name::of('V'), PdfNumber::ofInt(4))
            ->withEntry(Name::of('R'), PdfNumber::ofInt(4))
            ->withEntry(Name::of('Length'), PdfNumber::ofInt(128))
            ->withEntry(Name::of('CF'), $cf)
            ->withEntry(Name::of('StmF'), Name::of('StdCF'))
            ->withEntry(Name::of('StrF'), Name::of('StdCF'))
            ->withEntry(Name::of('P'), PdfNumber::ofInt(-4))
            ->withEntry(Name::of('O'), self::hex($o))
            ->withEntry(Name::of('U'), self::hex($u))
            ->withEntry(Name::of('EncryptMetadata'), PdfBoolean::of(false));

        $params = EncryptionParams::fromTrailer($encrypt, self::trailer('id4'), self::identityResolve());

        self::assertSame(4, $params->v);
        self::assertSame(4, $params->r);
        self::assertSame(16, $params->keyLengthBytes);
        self::assertSame(StreamCipher::Aesv2, $params->stmCipher);
        self::assertSame(StreamCipher::Aesv2, $params->strCipher);
        self::assertFalse($params->encryptMetadata);
        self::assertSame('id4', $params->idFirst);
        self::assertSame($o, $params->o);
        self::assertSame($u, $params->u);
    }

    public function testParsesV5Aesv3(): void
    {
        $o  = str_repeat("\x55", 48);
        $u  = str_repeat("\x66", 48);
        $oe = str_repeat("\x77", 32);
        $ue = str_repeat("\x88", 32);
        $encrypt = Dictionary::empty()
            ->withEntry(Name::of('Filter'), Name::of('Standard'))
            ->withEntry(Name::of('V'), PdfNumber::ofInt(5))
            ->withEntry(Name::of('R'), PdfNumber::ofInt(6))
            ->withEntry(Name::of('Length'), PdfNumber::ofInt(256))
            ->withEntry(Name::of('P'), PdfNumber::ofInt(-3904))
            ->withEntry(Name::of('O'), self::hex($o))
            ->withEntry(Name::of('U'), self::hex($u))
            ->withEntry(Name::of('OE'), self::hex($oe))
            ->withEntry(Name::of('UE'), self::hex($ue));

        $params = EncryptionParams::fromTrailer($encrypt, self::trailer('id5-binary'), self::identityResolve());

        self::assertSame(5, $params->v);
        self::assertSame(6, $params->r);
        self::assertSame(32, $params->keyLengthBytes);
        self::assertSame(StreamCipher::Aesv3, $params->stmCipher);
        self::assertSame(StreamCipher::Aesv3, $params->strCipher);
        self::assertSame('id5-binary', $params->idFirst);
        self::assertSame($o, $params->o);
        self::assertSame($u, $params->u);
        self::assertSame($oe, $params->oe);
        self::assertSame($ue, $params->ue);
    }

    public function testRejectsNonStandardFilter(): void
    {
        $encrypt = Dictionary::empty()
            ->withEntry(Name::of('Filter'), Name::of('Adobe.PubSec'))
            ->withEntry(Name::of('V'), PdfNumber::ofInt(5))
            ->withEntry(Name::of('R'), PdfNumber::ofInt(6));

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Adobe.PubSec');
        EncryptionParams::fromTrailer($encrypt, self::trailer('x'), self::identityResolve());
    }

    public function testRejectsUnsupportedVersion(): void
    {
        $encrypt = Dictionary::empty()
            ->withEntry(Name::of('Filter'), Name::of('Standard'))
            ->withEntry(Name::of('V'), PdfNumber::ofInt(3))
            ->withEntry(Name::of('R'), PdfNumber::ofInt(3));

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('V=3');
        EncryptionParams::fromTrailer($encrypt, self::trailer('x'), self::identityResolve());
    }

    public function testResolvesIndirectEncryptReferences(): void
    {
        $o  = str_repeat("\x55", 48);
        $u  = str_repeat("\x66", 48);
        $oe = str_repeat("\x77", 32);
        $ue = str_repeat("\x88", 32);

        // A tiny object table the resolver dereferences against: /O and the
        // first /ID element are stored as indirect references, as real files do.
        $table = [
            10 => self::hex($o),
            11 => self::hex('id5-indirect'),
        ];
        $resolve = static function (PdfObject $object) use ($table): PdfObject {
            if ($object instanceof PdfReference) {
                return $table[$object->objectNumber];
            }
            return $object;
        };

        $encrypt = Dictionary::empty()
            ->withEntry(Name::of('Filter'), Name::of('Standard'))
            ->withEntry(Name::of('V'), PdfNumber::ofInt(5))
            ->withEntry(Name::of('R'), PdfNumber::ofInt(6))
            ->withEntry(Name::of('Length'), PdfNumber::ofInt(256))
            ->withEntry(Name::of('P'), PdfNumber::ofInt(-3904))
            ->withEntry(Name::of('O'), PdfReference::to(10, 0))
            ->withEntry(Name::of('U'), self::hex($u))
            ->withEntry(Name::of('OE'), self::hex($oe))
            ->withEntry(Name::of('UE'), self::hex($ue));

        $trailer = Dictionary::empty()->withEntry(
            Name::of('ID'),
            PdfArray::of(PdfReference::to(11, 0), PdfString::of('id5-indirect')),
        );

        $params = EncryptionParams::fromTrailer($encrypt, $trailer, $resolve);

        self::assertSame($o, $params->o);
        self::assertSame($u, $params->u);
        self::assertSame('id5-indirect', $params->idFirst);
        self::assertSame(32, $params->keyLengthBytes);
        self::assertSame(StreamCipher::Aesv3, $params->stmCipher);
    }

    public function testAesv2KeyLengthIgnoresBitsInCfLength(): void
    {
        // Producer wrote the CF /Length in BITS (128) rather than bytes; the
        // AES key must still be 16 bytes (AES-128) regardless of the field.
        $stdCf = Dictionary::empty()
            ->withEntry(Name::of('CFM'), Name::of('AESV2'))
            ->withEntry(Name::of('Length'), PdfNumber::ofInt(128));
        $cf = Dictionary::empty()->withEntry(Name::of('StdCF'), $stdCf);
        $encrypt = Dictionary::empty()
            ->withEntry(Name::of('Filter'), Name::of('Standard'))
            ->withEntry(Name::of('V'), PdfNumber::ofInt(4))
            ->withEntry(Name::of('R'), PdfNumber::ofInt(4))
            ->withEntry(Name::of('Length'), PdfNumber::ofInt(128))
            ->withEntry(Name::of('CF'), $cf)
            ->withEntry(Name::of('StmF'), Name::of('StdCF'))
            ->withEntry(Name::of('StrF'), Name::of('StdCF'))
            ->withEntry(Name::of('P'), PdfNumber::ofInt(-4))
            ->withEntry(Name::of('O'), self::hex(str_repeat("\x33", 32)))
            ->withEntry(Name::of('U'), self::hex(str_repeat("\x44", 32)));

        $params = EncryptionParams::fromTrailer($encrypt, self::trailer('id4'), self::identityResolve());

        self::assertSame(16, $params->keyLengthBytes);
        self::assertSame(StreamCipher::Aesv2, $params->stmCipher);
    }

    public function testRejectsIdentityStreamFilter(): void
    {
        $stdCf = Dictionary::empty()->withEntry(Name::of('CFM'), Name::of('AESV2'));
        $cf = Dictionary::empty()->withEntry(Name::of('StdCF'), $stdCf);
        $encrypt = Dictionary::empty()
            ->withEntry(Name::of('Filter'), Name::of('Standard'))
            ->withEntry(Name::of('V'), PdfNumber::ofInt(4))
            ->withEntry(Name::of('R'), PdfNumber::ofInt(4))
            ->withEntry(Name::of('CF'), $cf)
            ->withEntry(Name::of('StmF'), Name::of('Identity'))
            ->withEntry(Name::of('StrF'), Name::of('StdCF'))
            ->withEntry(Name::of('P'), PdfNumber::ofInt(-4))
            ->withEntry(Name::of('O'), self::hex(str_repeat("\x33", 32)))
            ->withEntry(Name::of('U'), self::hex(str_repeat("\x44", 32)));

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Identity crypt filter not supported');
        EncryptionParams::fromTrailer($encrypt, self::trailer('id4'), self::identityResolve());
    }

    public function testForAesv3Convenience(): void
    {
        $params = EncryptionParams::forAesv3('o', 'u', 'oe', 'ue', -1, true, 'idf');

        self::assertSame(5, $params->v);
        self::assertSame(6, $params->r);
        self::assertSame(32, $params->keyLengthBytes);
        self::assertSame(StreamCipher::Aesv3, $params->stmCipher);
        self::assertSame(StreamCipher::Aesv3, $params->strCipher);
        self::assertSame('idf', $params->idFirst);
    }
}
