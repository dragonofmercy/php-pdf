<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DateTimeImmutable;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\Signature;
use DragonOfMercy\PhpPdf\Signature\SignatureDictionaryEmitter;
use DragonOfMercy\PhpPdf\Signature\SignaturePatcher;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use PHPUnit\Framework\TestCase;

final class SignaturePatcherTest extends TestCase
{
    private function sig(int $max): Signature
    {
        $gen = TestCertificate::generate();
        $cred = SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);
        return new Signature($cred, 'sig', null, null, null, new DateTimeImmutable(), $max);
    }

    public function testPatchPreservesLengthAndFillsByteRangeAndContents(): void
    {
        $max = 64;
        $contents = '<' . str_repeat('0', $max * 2) . '>';
        $prefix = "%PDF-1.7\n1 0 obj\n<< /ByteRange "
            . SignatureDictionaryEmitter::BYTERANGE_PLACEHOLDER
            . " /Contents ";
        $suffix = " >>\nendobj\n%%EOF";
        $bytes = $prefix . $contents . $suffix;
        $originalLength = strlen($bytes);

        // Stub signer: returns fixed DER bytes regardless of input.
        $stub = static fn (string $data): string => "\x30\x82\x00\x06ABCDEF";

        $patched = (new SignaturePatcher($stub))->patch($bytes, $this->sig($max));

        self::assertSame($originalLength, strlen($patched), 'length must be preserved');

        $p = strpos($patched, '/Contents <');
        self::assertNotFalse($p);
        $lt = strpos($patched, '<', $p);
        self::assertNotFalse($lt);
        $gt = strpos($patched, '>', $lt);
        self::assertNotFalse($gt);

        $expectedByteRange = sprintf('[0 %010d %010d %010d]', $lt, $gt + 1, $originalLength - ($gt + 1));
        self::assertStringContainsString('/ByteRange ' . $expectedByteRange, $patched);

        $hex = substr($patched, $lt + 1, $gt - $lt - 1);
        self::assertSame($max * 2, strlen($hex));
        // bin2hex("\x30\x82\x00\x06ABCDEF") = '30820006414243444546', then zero padding.
        self::assertStringStartsWith('30820006414243444546', $hex);
        self::assertSame(str_pad('30820006414243444546', $max * 2, '0', STR_PAD_RIGHT), $hex);
    }

    public function testSignatureTooLargeThrows(): void
    {
        $max = 4; // 8 hex chars capacity
        $contents = '<' . str_repeat('0', $max * 2) . '>';
        $bytes = "x/ByteRange " . SignatureDictionaryEmitter::BYTERANGE_PLACEHOLDER . " /Contents " . $contents . " y";
        $big = static fn (string $d): string => str_repeat("\xAB", 100); // 200 hex chars > 8
        $this->expectException(PdfException::class);
        (new SignaturePatcher($big))->patch($bytes, $this->sig($max));
    }

    public function testMissingContentsPlaceholderThrows(): void
    {
        $stub = static fn (string $d): string => 'x';
        $bytes = "%PDF no signature here %%EOF";
        $this->expectException(PdfException::class);
        (new SignaturePatcher($stub))->patch($bytes, $this->sig(64));
    }
}
