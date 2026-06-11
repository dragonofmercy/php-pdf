<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Pdf;
use DragonOfMercy\PhpPdf\Signature\Ltv\CertificateChain;
use DragonOfMercy\PhpPdf\Signature\Ltv\StaticValidationDataSource;
use DragonOfMercy\PhpPdf\Signature\Ltv\ValidationMaterial;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use DragonOfMercy\PhpPdf\Tests\Support\TestPki;
use DragonOfMercy\PhpPdf\Tests\Support\TestTsa;
use PHPUnit\Framework\TestCase;

final class PdfSignExistingLtvTest extends TestCase
{
    public function testEnableLtvRequiresSignature(): void
    {
        $doc = new Document();
        $doc->addPage();
        $pdf = Pdf::fromBytes($doc->output());
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('~requires at least one signature~');
        $pdf->enableLtv();
    }

    public function testSignThenLtvEmbedsDssAndDocTimeStamp(): void
    {
        $pki = TestPki::issueWithCrl();
        if ($pki === null || !function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl CLI/ext unavailable');
        }
        $doc = new Document();
        $doc->addPage();
        $source = $doc->output();

        $cred = SigningCertificate::fromPkcs12Bytes($pki['leafP12'], $pki['password']);
        $material = ValidationMaterial::of(
            [CertificateChain::pemToDer($pki['leafPem']), CertificateChain::pemToDer($pki['rootPem'])],
            [$pki['crlDer']],
        );

        $pdf = Pdf::fromBytes($source);
        $pdf->sign($cred, field: 'LtvSig', reason: 'LTV');
        $pdf->enableLtv(new StaticValidationDataSource($material), Tsa::withClient(new TestTsa()));
        $bytes = $pdf->output();

        self::assertSame($source, substr($bytes, 0, strlen($source)));
        self::assertStringContainsString('/Type /DSS', $bytes);
        self::assertStringContainsString('/DocTimeStamp', $bytes);
        self::assertSame(2, substr_count($bytes, '/ByteRange'));
    }
}
