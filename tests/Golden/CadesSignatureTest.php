<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\SignatureField;
use DragonOfMercy\PhpPdf\Signature\SignatureFormat;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use DragonOfMercy\PhpPdf\Tests\Support\TestPki;
use DragonOfMercy\PhpPdf\Tests\Support\TestTsa;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * End-to-end acceptance for the strict ETSI.CAdES profile: sign with
 * SignatureFormat::EtsiCadesDetached and validate with pyHanko that the
 * signature is intact + valid, carries /SubFilter /ETSI.CAdES.detached, and
 * includes the signingCertificateV2 signed attribute (a tampered certHash would
 * fail validation). A second case adds a signature timestamp (B-T). Auto-skips
 * when openssl / pyHanko are absent.
 *
 * The CMS is hex-encoded inside the signature /Contents, so OID presence is
 * asserted against the uppercase hex of the OID's DER encoding.
 */
final class CadesSignatureTest extends TestCase
{
    // signingCertificateV2 OID DER: 06 0B 2A 86 48 86 F7 0D 01 09 10 02 2F
    private const string OID_SIGNING_CERT_V2_HEX = '060B2A864886F70D010910022F';
    // id-aa-timeStampToken OID DER: 06 0B 2A 86 48 86 F7 0D 01 09 10 02 0E
    private const string OID_TIMESTAMP_TOKEN_HEX = '060B2A864886F70D010910020E';

    /**
     * @return array{bytes: string, rootPem: string}
     */
    private function buildCadesDocument(bool $withTimestamp): array
    {
        $pki = TestPki::issueWithOcsp();
        if ($pki === null || !function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl CLI/ext unavailable');
        }
        $cred = SigningCertificate::fromPkcs12Bytes($pki['leafP12'], $pki['password']);

        $doc = new Document();
        $page = $doc->addPage();
        $page->field(SignatureField::visible(20, 20, 80, 20, name: 'sig'));
        $doc->sign(
            $cred,
            field: 'sig',
            reason: 'CAdES',
            timestamp: $withTimestamp ? Tsa::withClient(new TestTsa()) : null,
            format: SignatureFormat::EtsiCadesDetached,
        );

        return ['bytes' => $doc->output(), 'rootPem' => $pki['rootPem']];
    }

    public function testCadesSubFilterAndAttributePresent(): void
    {
        ['bytes' => $bytes] = $this->buildCadesDocument(false);
        self::assertStringContainsString('/SubFilter /ETSI.CAdES.detached', $bytes);
        self::assertStringContainsString(self::OID_SIGNING_CERT_V2_HEX, $bytes);
    }

    public function testBaselineTAddsTimestamp(): void
    {
        ['bytes' => $bytes] = $this->buildCadesDocument(true);
        self::assertStringContainsString('/SubFilter /ETSI.CAdES.detached', $bytes);
        self::assertStringContainsString(self::OID_TIMESTAMP_TOKEN_HEX, $bytes);
    }

    public function testPyHankoValidatesCades(): void
    {
        $python = 'C:/tmp/pdfsig-venv/Scripts/python.exe';
        if (!is_file($python)) {
            self::markTestSkipped('pyHanko venv unavailable');
        }
        ['bytes' => $bytes, 'rootPem' => $rootPem] = $this->buildCadesDocument(false);
        $pdf = (string) tempnam(sys_get_temp_dir(), 'cades');
        $root = (string) tempnam(sys_get_temp_dir(), 'root');
        try {
            file_put_contents($pdf, $bytes);
            file_put_contents($root, $rootPem);
            $script = __DIR__ . '/assets/cades-validate.py';
            $p = new Process([$python, $script, $pdf, $root]);
            $p->run();
            self::assertStringContainsString('OK', $p->getOutput(),
                'pyHanko CAdES validation failed: ' . $p->getOutput() . $p->getErrorOutput());
            self::assertSame(0, $p->getExitCode());
        } finally {
            @unlink($pdf);
            @unlink($root);
        }
    }
}
