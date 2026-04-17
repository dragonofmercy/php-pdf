<?php

declare(strict_types=1);

namespace PhpPdf\Document;

/**
 * Fluent builder for PDF encryption settings (R6 / AES-256 only).
 * All permissions default to denied; explicitly allow with allow* methods.
 */
final class Encryption
{
    private const int PRINT_BITS = 0x804;
    private const int COPY_BITS = 0x210;
    private const int MODIFY_BITS = 0x528;

    public ?string $userPassword = null;
    public ?string $ownerPassword = null;

    /** 32-bit permissions bitfield. Bits 1-2 are reserved and set. */
    public int $permissions = 0b11;

    public bool $encryptMetadata = false;

    /** @var (callable(int): string)|null */
    public $randomSource = null;

    public function userPassword(string $password): self
    {
        $this->userPassword = $password;
        return $this;
    }

    public function ownerPassword(string $password): self
    {
        $this->ownerPassword = $password;
        return $this;
    }

    public function allowPrint(): self
    {
        $this->permissions |= self::PRINT_BITS;
        return $this;
    }

    public function allowCopy(): self
    {
        $this->permissions |= self::COPY_BITS;
        return $this;
    }

    public function allowModify(): self
    {
        $this->permissions |= self::MODIFY_BITS;
        return $this;
    }

    public function encryptMetadata(bool $value): self
    {
        $this->encryptMetadata = $value;
        return $this;
    }

    /**
     * @param callable(int): string $source returns N cryptographically-appropriate bytes
     */
    public function withRandomSource(callable $source): self
    {
        $this->randomSource = $source;
        return $this;
    }
}
