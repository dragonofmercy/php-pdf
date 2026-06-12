<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Encryption\Reader;

use DragonOfMercy\PhpPdf\Encryption\PasswordHash;
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Authenticates a password against a parsed Standard /Encrypt dictionary and
 * recovers the file encryption key.
 *
 * This task implements the modern AES-256 (R5/R6, V5) scheme using
 * ISO 32000-2 Algorithms 2.A / 11 / 12 (the recursive hash being Algorithm
 * 2.B in {@see PasswordHash}). The legacy RC4 / AES-128 (Algorithm 2) path is
 * added in a later task; the cipher dispatch is structured to receive it.
 *
 * @internal
 */
final class StandardSecurityHandler
{
    private ?string $fileKey = null;

    public function __construct(
        private readonly EncryptionParams $params,
        private readonly PasswordHash $passwordHash,
    ) {}

    /**
     * Tries the empty password then, if supplied, the given password, each as
     * user then owner. Stores the recovered file key and returns $this on the
     * first match; throws on total failure.
     */
    public function authenticate(?string $password): self
    {
        $candidates = [''];
        if ($password !== null && $password !== '') {
            $candidates[] = $password;
        }

        foreach ($candidates as $candidate) {
            $fileKey = $this->tryCandidate($candidate);
            if ($fileKey !== null) {
                $this->fileKey = $fileKey;
                return $this;
            }
        }

        throw new PdfException(
            $password !== null && $password !== ''
                ? 'incorrect password'
                : 'password required to open this encrypted PDF',
        );
    }

    public function fileKey(): string
    {
        if ($this->fileKey === null) {
            throw new PdfException('Cannot read file key before authenticate()');
        }
        return $this->fileKey;
    }

    /**
     * Attempts to recover the file key for one candidate password, trying the
     * user then owner role. Returns the 32-byte file key or null if neither
     * role validates.
     */
    private function tryCandidate(string $password): ?string
    {
        return match ($this->params->stmCipher) {
            StreamCipher::Aesv3 => $this->tryAesv3($password),
            StreamCipher::Rc4, StreamCipher::Aesv2 => throw new PdfException(
                'Legacy RC4 / AES-128 authentication not yet implemented',
            ),
        };
    }

    /**
     * AES-256 (R6) authentication: ISO 32000-2 Algorithms 2.A / 11 / 12.
     */
    private function tryAesv3(string $password): ?string
    {
        // User: validate against U[0..32), recover key from UE.
        $userValidation = $this->passwordHash->hash($password, substr($this->params->u, 32, 8), '');
        if (hash_equals(substr($this->params->u, 0, 32), $userValidation)) {
            $intermediateKey = $this->passwordHash->hash($password, substr($this->params->u, 40, 8), '');
            return $this->aesNoPadCbcDecrypt($intermediateKey, $this->params->ue);
        }

        // Owner: validate against O[0..32) using the 48-byte U as additional
        // input, recover key from OE.
        $udk = substr($this->params->u, 0, 48);
        $ownerValidation = $this->passwordHash->hash($password, substr($this->params->o, 32, 8), $udk);
        if (hash_equals(substr($this->params->o, 0, 32), $ownerValidation)) {
            $intermediateKey = $this->passwordHash->hash($password, substr($this->params->o, 40, 8), $udk);
            return $this->aesNoPadCbcDecrypt($intermediateKey, $this->params->oe);
        }

        return null;
    }

    /**
     * AES-256-CBC decrypt with a zero IV and no padding (key unwrap for UE/OE).
     */
    private function aesNoPadCbcDecrypt(string $key, string $data): string
    {
        $plaintext = openssl_decrypt(
            $data,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            str_repeat("\x00", 16),
        );
        if ($plaintext === false) {
            throw new PdfException('AES-256-CBC key unwrap failed');
        }
        return $plaintext;
    }
}
