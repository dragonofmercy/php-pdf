<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Encryption;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Derives the R6 encryption data: file key, U/O validation/key material,
 * encrypted UE/OE (wrapping the file key), and Perms.
 *
 * Computed lazily on first access; caches results on the object.
 *
 * @internal
 */
final class EncryptionKey
{
    private readonly string $fileKey;
    private ?string $u = null;
    private ?string $o = null;
    private ?string $ue = null;
    private ?string $oe = null;
    private ?string $perms = null;

    /** @var callable(int): string */
    private $randomSource;

    /**
     * @param callable(int): string $randomSource
     */
    public function __construct(
        private readonly string $userPassword,
        private readonly string $ownerPassword,
        private readonly int $permissions,
        private readonly bool $encryptMetadata,
        callable $randomSource,
        private readonly PasswordHash $passwordHash,
        private readonly Cipher $cipher,
    ) {
        $this->randomSource = $randomSource;
        $this->fileKey = ($randomSource)(32);
        if (strlen($this->fileKey) !== 32) {
            throw new PdfException('randomSource(32) must return exactly 32 bytes');
        }
    }

    public function fileKey(): string
    {
        return $this->fileKey;
    }

    public function u(): string
    {
        if ($this->u !== null) {
            return $this->u;
        }
        $validationSalt = ($this->randomSource)(8);
        $keySalt = ($this->randomSource)(8);
        $validationHash = $this->passwordHash->hash($this->userPassword, $validationSalt, '');
        return $this->u = $validationHash . $validationSalt . $keySalt;
    }

    public function ue(): string
    {
        if ($this->ue !== null) {
            return $this->ue;
        }
        $this->u();
        assert($this->u !== null);
        $keySalt = substr($this->u, 40, 8);
        $intermediateKey = $this->passwordHash->hash($this->userPassword, $keySalt, '');
        $ct = openssl_encrypt(
            $this->fileKey,
            'aes-256-cbc',
            $intermediateKey,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            str_repeat("\x00", 16),
        );
        if ($ct === false) {
            throw new PdfException('UE encryption failed');
        }
        $this->ue = $ct;
        return $this->ue;
    }

    public function o(): string
    {
        if ($this->o !== null) {
            return $this->o;
        }
        $this->u();
        assert($this->u !== null);
        $validationSalt = ($this->randomSource)(8);
        $keySalt = ($this->randomSource)(8);
        $validationHash = $this->passwordHash->hash($this->ownerPassword, $validationSalt, $this->u);
        return $this->o = $validationHash . $validationSalt . $keySalt;
    }

    public function oe(): string
    {
        if ($this->oe !== null) {
            return $this->oe;
        }
        $this->o();
        assert($this->o !== null);
        $keySalt = substr($this->o, 40, 8);
        $intermediateKey = $this->passwordHash->hash($this->ownerPassword, $keySalt, $this->u());
        $ct = openssl_encrypt(
            $this->fileKey,
            'aes-256-cbc',
            $intermediateKey,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            str_repeat("\x00", 16),
        );
        if ($ct === false) {
            throw new PdfException('OE encryption failed');
        }
        $this->oe = $ct;
        return $this->oe;
    }

    public function perms(): string
    {
        if ($this->perms !== null) {
            return $this->perms;
        }
        $pBytes = pack('V', $this->permissions);
        $emFlag = $this->encryptMetadata ? 'T' : 'F';
        $pad = ($this->randomSource)(4);
        if (strlen($pad) !== 4) {
            throw new PdfException('randomSource(4) must return 4 bytes');
        }
        $plaintext = $pBytes . "\xFF\xFF\xFF\xFF" . $emFlag . 'adb' . $pad;
        assert(strlen($plaintext) === 16);
        return $this->perms = $this->cipher->encryptEcb($plaintext, $this->fileKey);
    }
}
