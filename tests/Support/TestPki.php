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

    /**
     * @return array{rootPem: string, leafPem: string, ocspSignerPem: string, leafP12: string, password: string, ocspResponseDer: string, dir: string}|null
     */
    public static function issueWithOcsp(): ?array
    {
        $openssl = (new ExecutableFinder())->find('openssl');
        if ($openssl === null) {
            return null;
        }
        $dir = sys_get_temp_dir() . '/phppdf-ocsp-' . bin2hex(random_bytes(6));
        if (!mkdir($dir) && !is_dir($dir)) {
            return null;
        }
        $password = 'test-pass';
        $run = static function (array $args) use ($openssl): bool {
            $p = new Process([$openssl, ...$args]);
            $p->run();
            return $p->getExitCode() === 0;
        };

        file_put_contents($dir . '/index.txt', '');
        file_put_contents($dir . '/serial', "1000\n");
        file_put_contents($dir . '/crlnumber', "1000\n");
        file_put_contents($dir . '/ca.cnf', self::ocspCaConfig($dir));

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
        if (!$run(['genrsa', '-out', $dir . '/ocsp.key', '2048'])) {
            return null;
        }
        if (!$run(['req', '-new', '-key', $dir . '/ocsp.key', '-subj', '/CN=phppdf test ocsp responder',
            '-out', $dir . '/ocsp.csr', '-config', $dir . '/ca.cnf'])) {
            return null;
        }
        if (!$run(['ca', '-batch', '-config', $dir . '/ca.cnf', '-extensions', 'ocsp_ext',
            '-days', '825', '-in', $dir . '/ocsp.csr', '-out', $dir . '/ocsp.pem',
            '-keyfile', $dir . '/root.key', '-cert', $dir . '/root.pem'])) {
            return null;
        }
        if (!$run(['ocsp', '-issuer', $dir . '/root.pem', '-sha1', '-cert', $dir . '/leaf.pem',
            '-reqout', $dir . '/req.der', '-no_nonce'])) {
            return null;
        }
        if (!$run(['ocsp', '-index', $dir . '/index.txt', '-CA', $dir . '/root.pem',
            '-rsigner', $dir . '/ocsp.pem', '-rkey', $dir . '/ocsp.key',
            '-reqin', $dir . '/req.der', '-respout', $dir . '/resp.der', '-no_nonce'])) {
            return null;
        }
        if (!$run(['pkcs12', '-export', '-inkey', $dir . '/leaf.key', '-in', $dir . '/leaf.pem',
            '-certfile', $dir . '/root.pem', '-passout', 'pass:' . $password,
            '-out', $dir . '/leaf.p12'])) {
            return null;
        }

        $rootPem = (string) file_get_contents($dir . '/root.pem');
        $leafPem = (string) file_get_contents($dir . '/leaf.pem');
        $ocspSignerPem = (string) file_get_contents($dir . '/ocsp.pem');
        $leafP12 = (string) file_get_contents($dir . '/leaf.p12');
        $ocspResponseDer = (string) file_get_contents($dir . '/resp.der');
        if ($rootPem === '' || $leafPem === '' || $ocspSignerPem === '' || $leafP12 === '' || $ocspResponseDer === '') {
            return null;
        }

        return [
            'rootPem' => $rootPem,
            'leafPem' => $leafPem,
            'ocspSignerPem' => $ocspSignerPem,
            'leafP12' => $leafP12,
            'password' => $password,
            'ocspResponseDer' => $ocspResponseDer,
            'dir' => $dir,
        ];
    }

    /**
     * @return array{rootPem: string, signerPem: string, signerP12: string, password: string, tsaPem: string, tsaKeyPem: string, crlDer: string, tsaConfigPath: string, dir: string}|null
     */
    public static function issueTsaWithCrl(): ?array
    {
        $openssl = (new ExecutableFinder())->find('openssl');
        if ($openssl === null) {
            return null;
        }
        $dir = sys_get_temp_dir() . '/phppdf-blta-' . bin2hex(random_bytes(6));
        if (!mkdir($dir) && !is_dir($dir)) {
            return null;
        }
        $password = 'test-pass';
        $run = static function (array $args) use ($openssl): bool {
            $p = new Process([$openssl, ...$args]);
            $p->run();
            return $p->getExitCode() === 0;
        };

        $crlUrl = 'http://crl.example.com/blta.crl';
        file_put_contents($dir . '/index.txt', '');
        file_put_contents($dir . '/serial', "1000\n");
        file_put_contents($dir . '/crlnumber', "1000\n");
        file_put_contents($dir . '/ca.cnf', self::tsaCaConfig($dir, $crlUrl));
        file_put_contents($dir . '/ts.cnf', self::tsConfig($dir));
        file_put_contents($dir . '/tsserial', "01\n");

        if (!$run(['genrsa', '-out', $dir . '/root.key', '2048'])) {
            return null;
        }
        if (!$run(['req', '-x509', '-new', '-key', $dir . '/root.key', '-sha256', '-days', '3650',
            '-subj', '/CN=phppdf blta root', '-out', $dir . '/root.pem', '-config', $dir . '/ca.cnf'])) {
            return null;
        }
        if (!$run(['genrsa', '-out', $dir . '/signer.key', '2048'])) {
            return null;
        }
        if (!$run(['req', '-new', '-key', $dir . '/signer.key', '-subj', '/CN=phppdf blta signer',
            '-out', $dir . '/signer.csr', '-config', $dir . '/ca.cnf'])) {
            return null;
        }
        if (!$run(['ca', '-batch', '-config', $dir . '/ca.cnf', '-extensions', 'signer_ext', '-days', '825',
            '-in', $dir . '/signer.csr', '-out', $dir . '/signer.pem', '-keyfile', $dir . '/root.key', '-cert', $dir . '/root.pem'])) {
            return null;
        }
        if (!$run(['genrsa', '-out', $dir . '/tsa.key', '2048'])) {
            return null;
        }
        if (!$run(['req', '-new', '-key', $dir . '/tsa.key', '-subj', '/CN=phppdf blta tsa',
            '-out', $dir . '/tsa.csr', '-config', $dir . '/ca.cnf'])) {
            return null;
        }
        if (!$run(['ca', '-batch', '-config', $dir . '/ca.cnf', '-extensions', 'tsa_ext', '-days', '825',
            '-in', $dir . '/tsa.csr', '-out', $dir . '/tsa.pem', '-keyfile', $dir . '/root.key', '-cert', $dir . '/root.pem'])) {
            return null;
        }
        if (!$run(['ca', '-gencrl', '-config', $dir . '/ca.cnf', '-keyfile', $dir . '/root.key',
            '-cert', $dir . '/root.pem', '-out', $dir . '/crl.pem'])) {
            return null;
        }
        if (!$run(['crl', '-in', $dir . '/crl.pem', '-outform', 'DER', '-out', $dir . '/crl.der'])) {
            return null;
        }
        if (!$run(['pkcs12', '-export', '-inkey', $dir . '/signer.key', '-in', $dir . '/signer.pem',
            '-certfile', $dir . '/root.pem', '-passout', 'pass:' . $password, '-out', $dir . '/signer.p12'])) {
            return null;
        }

        $rootPem = (string) file_get_contents($dir . '/root.pem');
        $signerPem = (string) file_get_contents($dir . '/signer.pem');
        $tsaPem = (string) file_get_contents($dir . '/tsa.pem');
        $tsaKeyPem = (string) file_get_contents($dir . '/tsa.key');
        $signerP12 = (string) file_get_contents($dir . '/signer.p12');
        $crlDer = (string) file_get_contents($dir . '/crl.der');
        if ($rootPem === '' || $signerPem === '' || $tsaPem === '' || $tsaKeyPem === '' || $signerP12 === '' || $crlDer === '') {
            return null;
        }

        return [
            'rootPem' => $rootPem,
            'signerPem' => $signerPem,
            'signerP12' => $signerP12,
            'password' => $password,
            'tsaPem' => $tsaPem,
            'tsaKeyPem' => $tsaKeyPem,
            'crlDer' => $crlDer,
            'tsaConfigPath' => $dir . '/ts.cnf',
            'dir' => $dir,
        ];
    }

    private static function tsaCaConfig(string $dir, string $crlUrl): string
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
        commonName = supplied

        [ req ]
        distinguished_name = req_dn
        x509_extensions    = root_ext
        prompt             = no

        [ req_dn ]
        CN = phppdf blta

        [ root_ext ]
        basicConstraints = critical, CA:TRUE
        keyUsage = critical, keyCertSign, cRLSign
        subjectKeyIdentifier = hash

        [ signer_ext ]
        basicConstraints = critical, CA:FALSE
        keyUsage = critical, digitalSignature, nonRepudiation
        subjectKeyIdentifier = hash
        authorityKeyIdentifier = keyid,issuer
        crlDistributionPoints = URI:{$crlUrl}

        [ tsa_ext ]
        basicConstraints = critical, CA:FALSE
        keyUsage = critical, digitalSignature
        extendedKeyUsage = critical, timeStamping
        subjectKeyIdentifier = hash
        authorityKeyIdentifier = keyid,issuer
        crlDistributionPoints = URI:{$crlUrl}

        CNF;
    }

    private static function tsConfig(string $dir): string
    {
        $d = str_replace('\\', '/', $dir);
        return <<<CNF
        [ tsa ]
        default_tsa = tc

        [ tc ]
        signer_cert = {$d}/tsa.pem
        signer_key = {$d}/tsa.key
        certs = {$d}/root.pem
        default_policy = 1.2.3.4.1
        serial = {$d}/tsserial
        signer_digest = sha256
        digests = sha256
        accuracy = secs:1
        clock_precision_digits = 0
        ordering = yes
        tsa_name = no
        ess_cert_id_alg = sha256

        CNF;
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

    private static function ocspCaConfig(string $dir): string
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
        authorityInfoAccess = OCSP;URI:http://ocsp.example.com/

        [ ocsp_ext ]
        basicConstraints = critical, CA:FALSE
        keyUsage = critical, digitalSignature
        extendedKeyUsage = critical, OCSPSigning
        subjectKeyIdentifier = hash
        authorityKeyIdentifier = keyid,issuer
        1.3.6.1.5.5.7.48.1.5 = DER:05:00

        CNF;
    }
}
