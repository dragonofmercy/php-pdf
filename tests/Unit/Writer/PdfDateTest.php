<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Writer;

use DateTimeImmutable;
use DateTimeZone;
use DragonOfMercy\PhpPdf\Writer\PdfDate;
use PHPUnit\Framework\TestCase;

final class PdfDateTest extends TestCase
{
    public function testUtcUsesZSuffix(): void
    {
        $d = new DateTimeImmutable('2026-05-26 12:00:00', new DateTimeZone('UTC'));
        self::assertSame('D:20260526120000Z', PdfDate::format($d));
    }

    public function testPositiveOffsetUsesApostropheForm(): void
    {
        $d = new DateTimeImmutable('2026-05-26 12:00:00', new DateTimeZone('+02:00'));
        self::assertSame("D:20260526120000+02'00", PdfDate::format($d));
    }

    public function testNegativeOffset(): void
    {
        $d = new DateTimeImmutable('2026-05-26 12:00:00', new DateTimeZone('-05:30'));
        self::assertSame("D:20260526120000-05'30", PdfDate::format($d));
    }
}
