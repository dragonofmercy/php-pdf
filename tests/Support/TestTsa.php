<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Support;

use DragonOfMercy\PhpPdf\Signature\Asn1\Der;
use DragonOfMercy\PhpPdf\Signature\TsaClient;
use RuntimeException;

/**
 * Test-only TsaClient. Produces a TimeStampToken-shaped CMS (SignedData over a
 * TSTInfo DER) signed by an ephemeral TSA certificate. Good enough to exercise
 * the embedding and parsing paths without a live TSA; not a CA-issued token.
 */
final class TestTsa implements TsaClient
{
    /** @var array{certPem: string, keyPem: string, p12: string, password: string}|null */
    private static ?array $credential = null;

    public function timestamp(string $messageImprint, string $hashOid): string
    {
        $tstInfo = $this->buildTstInfo($messageImprint, $hashOid);
        return $this->signAsCms($tstInfo);
    }

    private function buildTstInfo(string $messageImprint, string $hashOid): string
    {
        // TSTInfo ::= SEQUENCE { version INTEGER 1, policy OID, messageImprint,
        //   serialNumber INTEGER, genTime GeneralizedTime }
        $imprint = Der::sequence(
            Der::sequence(Der::oid($hashOid), Der::null()),
            Der::octetString($messageImprint),
        );
        $genTime = Der::tlv(0x18, '20260601000000Z');
        return Der::sequence(
            Der::integer(1),
            Der::oid('1.2.3.4.1'), // arbitrary test policy OID
            $imprint,
            Der::integer(1),
            $genTime,
        );
    }

    private function signAsCms(string $tstInfo): string
    {
        // One ephemeral TSA credential per process: the token only needs a valid
        // signer, not a fresh keypair per call (RSA keygen is expensive).
        $gen = self::$credential ??= TestCertificate::generate();
        $in = tempnam(sys_get_temp_dir(), 'tsa_in');
        $out = tempnam(sys_get_temp_dir(), 'tsa_out');
        if ($in === false || $out === false) {
            if ($in !== false) {
                @unlink($in);
            }
            if ($out !== false) {
                @unlink($out);
            }
            throw new RuntimeException('Failed to allocate TSA temp files');
        }
        try {
            file_put_contents($in, $tstInfo);
            $cert = openssl_x509_read($gen['certPem']);
            $key = openssl_pkey_get_private($gen['keyPem']);
            if ($cert === false || $key === false) {
                throw new RuntimeException('TestTsa: failed to load ephemeral credential');
            }
            // Not detached: embed the TSTInfo as eContent so the token is
            // self-contained, matching how a real token carries its TSTInfo.
            $ok = openssl_cms_sign($in, $out, $cert, $key, [],
                OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER);
            if ($ok === false) {
                throw new RuntimeException('TestTsa: openssl_cms_sign failed');
            }
            $der = file_get_contents($out);
            if ($der === false || $der === '') {
                throw new RuntimeException('TestTsa: empty CMS output');
            }
            return $der;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }
}
