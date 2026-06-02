<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\SignatureField;
use DragonOfMercy\PhpPdf\Signature\SignatureFormat;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

final class CadesSubFilterTest extends TestCase
{
    public function testCadesFormatWritesEtsiSubFilterAndValidCms(): void
    {
        $pki = TestPki::issueWithOcsp();
        if ($pki === null || !function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $cred = SigningCertificate::fromPkcs12Bytes($pki['leafP12'], $pki['password']);

        $doc = new Document();
        $page = $doc->addPage();
        $page->field(SignatureField::visible(20, 20, 80, 20, name: 'sig'));
        $doc->sign($cred, field: 'sig', reason: 'CAdES', format: SignatureFormat::EtsiCadesDetached);
        $bytes = $doc->output();

        self::assertStringContainsString('/SubFilter /ETSI.CAdES.detached', $bytes);
        // id-aa-signingCertificateV2 OID (1.2.840.113549.1.9.16.2.47) must appear in the hex-encoded CMS /Contents
        $oidHex = strtoupper('060b2a864886f70d010910022f');
        self::assertStringContainsString($oidHex, $bytes);
    }

    public function testDefaultFormatStaysPkcs7(): void
    {
        $pki = TestPki::issueWithOcsp();
        if ($pki === null || !function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $cred = SigningCertificate::fromPkcs12Bytes($pki['leafP12'], $pki['password']);

        $doc = new Document();
        $page = $doc->addPage();
        $page->field(SignatureField::visible(20, 20, 80, 20, name: 'sig'));
        $doc->sign($cred, field: 'sig', reason: 'PKCS7');
        $bytes = $doc->output();

        self::assertStringContainsString('/SubFilter /adbe.pkcs7.detached', $bytes);
    }
}
