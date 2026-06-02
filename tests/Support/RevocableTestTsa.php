<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Support;

use DragonOfMercy\PhpPdf\Signature\TsaClient;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Test TsaClient producing a genuine RFC 3161 timestamp token (eContentType
 * id-ct-TSTInfo, TSA signer certificate embedded) via the openssl `ts` command,
 * signed by the revocable TSA credential issued by TestPki::issueTsaWithCrl().
 * Unlike TestTsa (which emits an id-data CMS), this is a valid token that
 * pyHanko's timestamp validator accepts - required for the B-LTA tests.
 */
final class RevocableTestTsa implements TsaClient
{
    public function __construct(private string $dir) {}

    public function timestamp(string $messageImprint, string $hashOid): string
    {
        $openssl = (new ExecutableFinder())->find('openssl');
        if ($openssl === null) {
            throw new RuntimeException('RevocableTestTsa: openssl CLI unavailable');
        }
        $tsq = $this->dir . '/req.tsq';
        $token = $this->dir . '/resp.token';
        @unlink($token);

        $previous = getenv('OPENSSL_CONF');
        putenv('OPENSSL_CONF=' . $this->dir . '/ca.cnf');
        try {
            $query = new Process([$openssl, 'ts', '-query', '-digest', bin2hex($messageImprint),
                '-sha256', '-cert', '-out', $tsq]);
            $query->run();
            if ($query->getExitCode() !== 0) {
                throw new RuntimeException('RevocableTestTsa: ts -query failed: ' . $query->getErrorOutput());
            }
            $reply = new Process([$openssl, 'ts', '-reply', '-queryfile', $tsq,
                '-signer', $this->dir . '/tsa.pem', '-inkey', $this->dir . '/tsa.key',
                '-chain', $this->dir . '/root.pem', '-token_out', '-out', $token,
                '-config', $this->dir . '/ts.cnf']);
            $reply->run();
            if ($reply->getExitCode() !== 0) {
                throw new RuntimeException('RevocableTestTsa: ts -reply failed: ' . $reply->getErrorOutput());
            }
        } finally {
            putenv($previous === false ? 'OPENSSL_CONF' : 'OPENSSL_CONF=' . $previous);
        }

        $der = file_get_contents($token);
        if ($der === false || $der === '') {
            throw new RuntimeException('RevocableTestTsa: empty token');
        }
        return $der;
    }
}
