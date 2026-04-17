<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Document;

use PhpPdf\Document\Encryption;
use PHPUnit\Framework\TestCase;

final class EncryptionTest extends TestCase
{
    public function testAllSettersReturnSameInstance(): void
    {
        $e = new Encryption();
        self::assertSame($e, $e->userPassword('user'));
        self::assertSame($e, $e->ownerPassword('owner'));
        self::assertSame($e, $e->allowPrint());
        self::assertSame($e, $e->allowCopy());
        self::assertSame($e, $e->allowModify());
        self::assertSame($e, $e->encryptMetadata(true));
        self::assertSame($e, $e->withRandomSource(fn (int $n): string => str_repeat("\x00", $n)));
    }

    public function testDefaults(): void
    {
        $e = new Encryption();
        self::assertNull($e->userPassword);
        self::assertNull($e->ownerPassword);
        self::assertSame(0xFFFFF0C0, $e->permissions);
        self::assertFalse($e->encryptMetadata);
        self::assertNull($e->randomSource);
    }

    public function testAllowPrintSetsExpectedBits(): void
    {
        $e = (new Encryption())->allowPrint();
        self::assertSame(0xFFFFF0C0 | 0x804, $e->permissions);
    }

    public function testAllowCopySetsExpectedBits(): void
    {
        $e = (new Encryption())->allowCopy();
        self::assertSame(0xFFFFF0C0 | 0x210, $e->permissions);
    }

    public function testAllowModifySetsExpectedBits(): void
    {
        $e = (new Encryption())->allowModify();
        self::assertSame(0xFFFFF0C0 | 0x528, $e->permissions);
    }

    public function testAllowMultipleAreUnioned(): void
    {
        $e = (new Encryption())->allowPrint()->allowCopy()->allowModify();
        self::assertSame(0xFFFFF0C0 | 0x804 | 0x210 | 0x528, $e->permissions);
    }
}
