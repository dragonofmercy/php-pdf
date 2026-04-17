<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Encryption;

use PhpPdf\Encryption\PasswordHash;
use PHPUnit\Framework\TestCase;

final class PasswordHashTest extends TestCase
{
    public function testReturnsThirtyTwoBytes(): void
    {
        $hash = (new PasswordHash())->hash('password', str_repeat("\x00", 8), '');
        self::assertSame(32, strlen($hash));
    }

    public function testIsDeterministicForSameInputs(): void
    {
        $ph = new PasswordHash();
        $a = $ph->hash('password', str_repeat("\x00", 8), '');
        $b = $ph->hash('password', str_repeat("\x00", 8), '');
        self::assertSame($a, $b);
    }

    public function testDifferentPasswordsProduceDifferentHashes(): void
    {
        $ph = new PasswordHash();
        $a = $ph->hash('password-a', str_repeat("\x00", 8), '');
        $b = $ph->hash('password-b', str_repeat("\x00", 8), '');
        self::assertNotSame($a, $b);
    }

    public function testDifferentSaltsProduceDifferentHashes(): void
    {
        $ph = new PasswordHash();
        $a = $ph->hash('password', str_repeat("\x00", 8), '');
        $b = $ph->hash('password', str_repeat("\x11", 8), '');
        self::assertNotSame($a, $b);
    }

    public function testUdkDifferentiatesOutput(): void
    {
        $ph = new PasswordHash();
        $a = $ph->hash('password', str_repeat("\x00", 8), '');
        $b = $ph->hash('password', str_repeat("\x00", 8), str_repeat("\xAA", 48));
        self::assertNotSame($a, $b);
    }

    public function testPasswordIsTruncatedAt127Bytes(): void
    {
        $ph = new PasswordHash();
        $long = str_repeat('a', 200);
        $truncated = str_repeat('a', 127);
        self::assertSame($ph->hash($long, str_repeat("\x00", 8), ''), $ph->hash($truncated, str_repeat("\x00", 8), ''));
    }
}
