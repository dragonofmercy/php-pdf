<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Signature\DocTimeStampDictionaryEmitter;
use DragonOfMercy\PhpPdf\Signature\DocTimeStampPatcher;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use DragonOfMercy\PhpPdf\Signature\TsaClient;
use DragonOfMercy\PhpPdf\Signature\TsaHashAlgorithm;
use PHPUnit\Framework\TestCase;

final class DocTimeStampPatcherTest extends TestCase
{
    public function testPatchesByteRangeAndContents(): void
    {
        $stub = new class implements TsaClient {
            public ?string $captured = null;
            public function timestamp(string $messageImprint, string $hashOid): string
            {
                $this->captured = $messageImprint;
                return "\xDE\xAD\xBE\xEF";
            }
        };
        $tsa = Tsa::withClient($stub, TsaHashAlgorithm::SHA256);

        $rev1 = "%PDF-1.7\nprior bytes\n%%EOF\n";
        $dictObj = (new DocTimeStampDictionaryEmitter())->emit(64, 5);
        $rev2 = "5 0 obj\n" . $dictObj->payload()->toBytes() . "\nendobj\n";
        $buffer = $rev1 . $rev2;

        $patched = (new DocTimeStampPatcher())->patch($buffer, $tsa, 64, strlen($rev1));

        self::assertSame(strlen($buffer), strlen($patched));
        self::assertStringNotContainsString('[0 0000000000 0000000000 0000000000]', $patched);
        if (preg_match('~/ByteRange \[0 (\d{10}) (\d{10}) (\d{10})\]~', $patched, $m) !== 1) {
            self::fail('ByteRange not patched');
        }
        $gapStart = (int) $m[1];
        $afterGap = (int) $m[2];
        $tailLen = (int) $m[3];
        self::assertSame(strlen($patched) - $afterGap, $tailLen);
        self::assertSame('<', $patched[$gapStart]);
        self::assertSame('>', $patched[$afterGap - 1]);
        $hex = substr($patched, $gapStart + 1, ($afterGap - 1) - ($gapStart + 1));
        self::assertStringStartsWith('DEADBEEF', $hex);
        $covered = substr($patched, 0, $gapStart) . substr($patched, $afterGap);
        self::assertSame(hash('sha256', $covered, true), $stub->captured);
    }
}
