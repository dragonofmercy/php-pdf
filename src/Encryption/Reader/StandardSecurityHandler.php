<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Encryption\Reader;

use DragonOfMercy\PhpPdf\Encryption\PasswordHash;
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Authenticates a password against a parsed Standard /Encrypt dictionary and
 * recovers the file encryption key.
 *
 * Implements both schemes, dispatched by the stream cipher: the modern AES-256
 * (R5/R6, V5) scheme via ISO 32000-2 Algorithms 2.A / 11 / 12 (the recursive
 * hash being Algorithm 2.B in {@see PasswordHash}), and the legacy RC4 /
 * AES-128 (R2-R4) scheme via ISO 32000-1 Algorithms 2 / 5 / 6 / 7. RC4 and
 * AES-128 share the Algorithm-2 file-key derivation; only per-object
 * decryption differs by cipher.
 *
 * @internal
 */
final class StandardSecurityHandler
{
    /**
     * The 32-byte ISO 32000-1 password-padding string (Algorithm 2, step a).
     */
    private const string PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

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

    public function streamCipher(): StreamCipher
    {
        return $this->params->stmCipher;
    }

    public function stringCipher(): StreamCipher
    {
        return $this->params->strCipher;
    }

    /**
     * ISO 32000-1 Algorithm 1: derive the per-object key for (objectNumber,
     * generation) under the given cipher. AES-256 (V5/R6) uses the file key
     * directly; RC4 and AES-128 mix the low object/generation bytes (plus the
     * "sAlT" suffix for AES-128) into an MD5 digest truncated to keyLength+5
     * bytes (capped at 16). Must be called after authenticate().
     */
    public function objectKey(int $objectNumber, int $generation, StreamCipher $cipher): string
    {
        $fileKey = $this->fileKey();

        return match ($cipher) {
            StreamCipher::Aesv3 => $fileKey,
            StreamCipher::Rc4, StreamCipher::Aesv2 => $this->legacyObjectKey($fileKey, $objectNumber, $generation, $cipher),
        };
    }

    /**
     * ISO 32000-1 Algorithm 1 for the legacy RC4 / AES-128 ciphers.
     */
    private function legacyObjectKey(string $fileKey, int $objectNumber, int $generation, StreamCipher $cipher): string
    {
        $input = $fileKey
            . substr(pack('V', $objectNumber), 0, 3)
            . substr(pack('V', $generation), 0, 2);

        if ($cipher === StreamCipher::Aesv2) {
            $input .= "\x73\x41\x6C\x54"; // "sAlT"
        }

        $h = md5($input, true);
        return substr($h, 0, min(strlen($fileKey) + 5, 16));
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
            StreamCipher::Rc4, StreamCipher::Aesv2 => $this->tryLegacy($password),
        };
    }

    /**
     * Legacy RC4 / AES-128 (R2-R4) authentication: ISO 32000-1 Algorithms
     * 2 / 5 / 6 / 7. RC4 and AES-128 share the same Algorithm-2 file-key
     * derivation; only per-object decryption differs by cipher.
     */
    private function tryLegacy(string $password): ?string
    {
        // User role (Algorithm 6): derive the key from the candidate as the
        // user password and validate the recomputed /U.
        $fileKey = $this->alg2FileKey($password);
        if ($this->validateUser($fileKey)) {
            return $fileKey;
        }

        // Owner role (Algorithm 7): recover the user password from /O using the
        // candidate as the owner password, then validate as the user.
        $recoveredUserPwd = $this->ownerToUser($password);
        $fileKey = $this->alg2FileKey($recoveredUserPwd);
        if ($this->validateUser($fileKey)) {
            return $fileKey;
        }

        return null;
    }

    /**
     * ISO 32000-1 Algorithm 2: compute the file encryption key from the user
     * password.
     */
    private function alg2FileKey(string $userPwd): string
    {
        $input = $this->padPassword($userPwd)
            . $this->params->o
            . pack('V', $this->params->p & 0xFFFFFFFF)
            . $this->params->idFirst;

        if ($this->params->r >= 4 && !$this->params->encryptMetadata) {
            $input .= "\xFF\xFF\xFF\xFF";
        }

        $key = md5($input, true);
        if ($this->params->r >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $key = md5(substr($key, 0, $this->params->keyLengthBytes), true);
            }
        }

        return substr($key, 0, $this->params->keyLengthBytes);
    }

    /**
     * ISO 32000-1 Algorithm 4 (R2) / 5 (R3-R4): compute the /U value a given
     * file key would produce. For R3/R4 the stored /U is 32 bytes but only the
     * first 16 are derived; this returns the 16-byte digest.
     */
    private function computeU(string $fileKey): string
    {
        if ($this->params->r === 2) {
            return Rc4Cipher::apply($fileKey, self::PAD);
        }

        $x = Rc4Cipher::apply($fileKey, md5(self::PAD . $this->params->idFirst, true));
        for ($i = 1; $i <= 19; $i++) {
            $x = Rc4Cipher::apply($this->xorKey($fileKey, $i), $x);
        }
        return $x;
    }

    /**
     * ISO 32000-1 Algorithm 6: validate the user password by comparing the
     * first 16 bytes of the recomputed /U against the stored /U.
     */
    private function validateUser(string $fileKey): bool
    {
        return hash_equals(substr($this->params->u, 0, 16), substr($this->computeU($fileKey), 0, 16));
    }

    /**
     * ISO 32000-1 Algorithm 7: recover the padded user password from /O using
     * the supplied owner password.
     */
    private function ownerToUser(string $ownerPwd): string
    {
        $rc4key = md5($this->padPassword($ownerPwd), true);
        if ($this->params->r >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $rc4key = md5(substr($rc4key, 0, $this->params->keyLengthBytes), true);
            }
        }
        $rc4key = substr($rc4key, 0, $this->params->keyLengthBytes);

        if ($this->params->r === 2) {
            return Rc4Cipher::apply($rc4key, $this->params->o);
        }

        $userPwd = $this->params->o;
        for ($i = 19; $i >= 0; $i--) {
            $userPwd = Rc4Cipher::apply($this->xorKey($rc4key, $i), $userPwd);
        }
        return $userPwd;
    }

    /**
     * Returns $key with every byte XORed by $i (the per-round key for the R3/R4
     * 19/20-round RC4 ladders).
     */
    private function xorKey(string $key, int $i): string
    {
        $rk = '';
        $len = strlen($key);
        for ($n = 0; $n < $len; $n++) {
            $rk .= chr((ord($key[$n]) ^ $i) & 0xFF);
        }
        return $rk;
    }

    /**
     * ISO 32000-1 Algorithm 2, step a: pad-or-truncate the password to 32 bytes
     * with the standard padding string.
     */
    private function padPassword(string $password): string
    {
        return substr($password . self::PAD, 0, 32);
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
