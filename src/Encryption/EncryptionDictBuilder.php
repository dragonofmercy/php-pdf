<?php

declare(strict_types=1);

namespace PhpPdf\Encryption;

use PhpPdf\Writer\Object\Dictionary;
use PhpPdf\Writer\Object\HexString;
use PhpPdf\Writer\Object\Name;
use PhpPdf\Writer\Object\PdfBoolean;
use PhpPdf\Writer\Object\PdfNumber;

/**
 * Builds the /Encrypt dictionary for PDF 2.0 R6 / AES-256.
 *
 * @internal
 */
final class EncryptionDictBuilder
{
    public function build(
        EncryptionKey $key,
        string $unusedId,
        bool $encryptMetadata,
        int $permissions,
    ): Dictionary {
        $stdCf = Dictionary::empty()
            ->withEntry(Name::of('CFM'), Name::of('AESV3'))
            ->withEntry(Name::of('Length'), PdfNumber::ofInt(32))
            ->withEntry(Name::of('AuthEvent'), Name::of('DocOpen'));
        $cf = Dictionary::empty()->withEntry(Name::of('StdCF'), $stdCf);

        $toHex = static fn (string $raw): HexString => HexString::of(bin2hex($raw));

        return Dictionary::empty()
            ->withEntry(Name::of('Filter'), Name::of('Standard'))
            ->withEntry(Name::of('V'), PdfNumber::ofInt(5))
            ->withEntry(Name::of('R'), PdfNumber::ofInt(6))
            ->withEntry(Name::of('Length'), PdfNumber::ofInt(256))
            ->withEntry(Name::of('CF'), $cf)
            ->withEntry(Name::of('StmF'), Name::of('StdCF'))
            ->withEntry(Name::of('StrF'), Name::of('StdCF'))
            ->withEntry(Name::of('P'), PdfNumber::ofInt($this->signed32($permissions)))
            ->withEntry(Name::of('U'), $toHex($key->u()))
            ->withEntry(Name::of('O'), $toHex($key->o()))
            ->withEntry(Name::of('UE'), $toHex($key->ue()))
            ->withEntry(Name::of('OE'), $toHex($key->oe()))
            ->withEntry(Name::of('Perms'), $toHex($key->perms()))
            ->withEntry(Name::of('EncryptMetadata'), PdfBoolean::of($encryptMetadata));
    }

    private function signed32(int $value): int
    {
        $value = $value & 0xFFFFFFFF;
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }
}
