<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Support;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Builds an ephemeral PKI with the openssl CLI for LTV tests: a root CA, a leaf
 * certificate issued by it that declares a CRL distribution point (a file:// URL
 * to the generated CRL), and a CRL signed by the root. Returns null when the
 * openssl CLI is not on PATH so tests can skip.
 */
final class TestPki
{
    /**
     * @return array{rootPem: string, leafPem: string, leafP12: string, password: string, crlDer: string, dir: string}|null
     */
    public static function issueWithCrl(): ?array
    {
        $openssl = (new ExecutableFinder())->find('openssl');
        if ($openssl === null) {
            return null;
        }
        $dir = sys_get_temp_dir() . '/phppdf-ltv-' . bin2hex(random_bytes(6));
        if (!mkdir($dir) && !is_dir($dir)) {
            return null;
        }
        $password = 'test-pass';
        $run = static function (array $args) use ($openssl): bool {
            $p = new Process([$openssl, ...$args]);
            $p->run();
            return $p->getExitCode() === 0;
        };

        $crlPath = $dir . '/leaf.crl';
        $crlUrl = 'file:///' . str_replace('\\', '/', $crlPath);

        file_put_contents($dir . '/index.txt', '');
        file_put_contents($dir . '/serial', "1000\n");
        file_put_contents($dir . '/crlnumber', "1000\n");
        file_put_contents($dir . '/ca.cnf', self::caConfig($dir, $crlUrl));

        if (!$run(['genrsa', '-out', $dir . '/root.key', '2048'])) {
            return null;
        }
        if (!$run(['req', '-x509', '-new', '-key', $dir . '/root.key', '-sha256',
            '-days', '3650', '-subj', '/CN=phppdf test root',
            '-out', $dir . '/root.pem', '-config', $dir . '/ca.cnf'])) {
            return null;
        }
        if (!$run(['genrsa', '-out', $dir . '/leaf.key', '2048'])) {
            return null;
        }
        if (!$run(['req', '-new', '-key', $dir . '/leaf.key', '-subj', '/CN=phppdf test signer',
            '-out', $dir . '/leaf.csr', '-config', $dir . '/ca.cnf'])) {
            return null;
        }
        if (!$run(['ca', '-batch', '-config', $dir . '/ca.cnf', '-extensions', 'leaf_ext',
            '-days', '825', '-in', $dir . '/leaf.csr', '-out', $dir . '/leaf.pem',
            '-keyfile', $dir . '/root.key', '-cert', $dir . '/root.pem'])) {
            return null;
        }
        if (!$run(['ca', '-gencrl', '-config', $dir . '/ca.cnf',
            '-keyfile', $dir . '/root.key', '-cert', $dir . '/root.pem',
            '-out', $dir . '/leaf.crl.pem'])) {
            return null;
        }
        if (!$run(['crl', '-in', $dir . '/leaf.crl.pem', '-outform', 'DER', '-out', $crlPath])) {
            return null;
        }
        if (!$run(['pkcs12', '-export', '-inkey', $dir . '/leaf.key', '-in', $dir . '/leaf.pem',
            '-certfile', $dir . '/root.pem', '-passout', 'pass:' . $password,
            '-out', $dir . '/leaf.p12'])) {
            return null;
        }

        $rootPem = (string) file_get_contents($dir . '/root.pem');
        $leafPem = (string) file_get_contents($dir . '/leaf.pem');
        $leafP12 = (string) file_get_contents($dir . '/leaf.p12');
        $crlDer = (string) file_get_contents($crlPath);
        if ($rootPem === '' || $leafPem === '' || $leafP12 === '' || $crlDer === '') {
            return null;
        }

        return [
            'rootPem' => $rootPem,
            'leafPem' => $leafPem,
            'leafP12' => $leafP12,
            'password' => $password,
            'crlDer' => $crlDer,
            'dir' => $dir,
        ];
    }

    private static function caConfig(string $dir, string $crlUrl): string
    {
        $d = str_replace('\\', '/', $dir);
        return <<<CNF
        [ ca ]
        default_ca = CA_default

        [ CA_default ]
        dir               = {$d}
        database          = {$d}/index.txt
        serial            = {$d}/serial
        crlnumber         = {$d}/crlnumber
        new_certs_dir     = {$d}
        default_md        = sha256
        default_days      = 825
        default_crl_days  = 30
        policy            = policy_any
        copy_extensions   = none
        email_in_dn       = no
        rand_serial       = no
        unique_subject    = no

        [ policy_any ]
        commonName              = supplied
        countryName             = optional
        stateOrProvinceName     = optional
        organizationName        = optional

        [ req ]
        distinguished_name = req_dn
        x509_extensions    = root_ext
        prompt             = no

        [ req_dn ]
        CN = phppdf test

        [ root_ext ]
        basicConstraints = critical, CA:TRUE
        keyUsage = critical, keyCertSign, cRLSign
        subjectKeyIdentifier = hash

        [ leaf_ext ]
        basicConstraints = critical, CA:FALSE
        keyUsage = critical, digitalSignature, nonRepudiation
        subjectKeyIdentifier = hash
        authorityKeyIdentifier = keyid,issuer
        crlDistributionPoints = URI:{$crlUrl}

        CNF;
    }
}
