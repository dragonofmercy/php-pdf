<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Cades;

use DragonOfMercy\PhpPdf\Signature\Asn1\Der;
use DragonOfMercy\PhpPdf\Signature\Cades\CmsSignedAttributes;
use DragonOfMercy\PhpPdf\Signature\Ltv\CertificateChain;
use DragonOfMercy\PhpPdf\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

final class CmsSignedAttributesTest extends TestCase
{
    public function testSigningAndEmbeddedFormsShareSortedContent(): void
    {
        $pki = TestPki::issueWithOcsp();
        if ($pki === null) {
            self::markTestSkipped('openssl CLI unavailable');
        }
        $certDer = CertificateChain::pemToDer($pki['leafPem']);
        $messageDigest = hash('sha256', 'the signed content', true);

        $attrs = new CmsSignedAttributes($messageDigest, $certDer);
        $signing = $attrs->signingForm();
        $embedded = $attrs->embeddedForm();

        self::assertSame(0x31, ord($signing[0]));
        self::assertSame(0xA0, ord($embedded[0]));
        $s = Der::readHeader($signing, 0);
        $e = Der::readHeader($embedded, 0);
        self::assertSame(
            substr($signing, $s['valueStart'], $s['length']),
            substr($embedded, $e['valueStart'], $e['length']),
        );

        $count = 0;
        $offset = $s['valueStart'];
        while ($offset < $s['end']) {
            $count++;
            $offset = Der::readHeader($signing, $offset)['end'];
        }
        self::assertSame(3, $count);

        $encodings = [];
        $offset = $s['valueStart'];
        while ($offset < $s['end']) {
            $h = Der::readHeader($signing, $offset);
            $encodings[] = substr($signing, $h['start'], $h['end'] - $h['start']);
            $offset = $h['end'];
        }
        $sorted = $encodings;
        sort($sorted);
        self::assertSame($sorted, $encodings);
    }
}
