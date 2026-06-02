<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Ltv;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\Ltv\CertificateChain;
use DragonOfMercy\PhpPdf\Signature\Ltv\StaticValidationDataSource;
use DragonOfMercy\PhpPdf\Signature\Ltv\ValidationMaterial;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use PHPUnit\Framework\TestCase;

final class EnableLtvTest extends TestCase
{
    public function testEnableLtvWithoutSignatureThrows(): void
    {
        $doc = new Document();
        $doc->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('enableLtv requires at least one signature');
        $doc->enableLtv(new StaticValidationDataSource(ValidationMaterial::of(['c'], [])));
    }

    public function testEnableLtvTwiceThrows(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $gen = TestCertificate::generate();
        $doc = new Document();
        $doc->addPage();
        $doc->addSignature(SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']));
        $der = CertificateChain::pemToDer($gen['certPem']);
        $source = new StaticValidationDataSource(ValidationMaterial::of([$der], []));
        $doc->enableLtv($source);
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('once');
        $doc->enableLtv($source);
    }

    public function testEnableLtvWithEmptyMaterialThrows(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $gen = TestCertificate::generate();
        $doc = new Document();
        $doc->addPage();
        $doc->addSignature(SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']));
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('no certificates or CRLs');
        $doc->enableLtv(new StaticValidationDataSource(ValidationMaterial::of([], [])));
    }

    public function testEnableLtvAddsDssToOutput(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $gen = TestCertificate::generate();
        $doc = new Document();
        $doc->addPage();
        $doc->addSignature(SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']));
        $der = CertificateChain::pemToDer($gen['certPem']);
        $doc->enableLtv(new StaticValidationDataSource(ValidationMaterial::of([$der], [])));
        $bytes = $doc->output();
        self::assertStringContainsString('/Type /DSS', $bytes);
    }
}
