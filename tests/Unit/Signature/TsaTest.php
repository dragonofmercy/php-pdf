<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\HttpTsaClient;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use DragonOfMercy\PhpPdf\Signature\TsaClient;
use DragonOfMercy\PhpPdf\Signature\TsaHashAlgorithm;
use PHPUnit\Framework\TestCase;

final class TsaTest extends TestCase
{
    public function testHttpConstructorDefaultsToSha256AndHttpClient(): void
    {
        $tsa = Tsa::http('https://tsa.example/tsr');
        self::assertSame(TsaHashAlgorithm::SHA256, $tsa->hash);
        self::assertInstanceOf(HttpTsaClient::class, $tsa->resolveClient());
    }

    public function testHttpRejectsEmptyUrl(): void
    {
        $this->expectException(PdfException::class);
        Tsa::http('');
    }

    public function testWithClientUsesInjectedClient(): void
    {
        $stub = new class implements TsaClient {
            public function timestamp(string $messageImprint, string $hashOid): string
            {
                return 'token';
            }
        };
        $tsa = Tsa::withClient($stub, TsaHashAlgorithm::SHA512);
        self::assertSame($stub, $tsa->resolveClient());
        self::assertSame(TsaHashAlgorithm::SHA512, $tsa->hash);
    }
}
