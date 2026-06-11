<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\IncrementalRevisionStacker;
use PHPUnit\Framework\TestCase;

final class IncrementalRevisionStackerStartxrefTest extends TestCase
{
    private function offset(string $bytes): int
    {
        $m = new \ReflectionMethod(IncrementalRevisionStacker::class, 'lastStartxrefOffset');
        $result = $m->invoke(new IncrementalRevisionStacker(), $bytes);
        self::assertIsInt($result);
        return $result;
    }

    public function testLfOnlyTail(): void
    {
        self::assertSame(12345, $this->offset("...\nstartxref\n12345\n%%EOF\n"));
    }

    public function testCrlfTail(): void
    {
        self::assertSame(678, $this->offset("...\r\nstartxref\r\n678\r\n%%EOF\r\n"));
    }

    public function testTrailingBytesAfterEof(): void
    {
        self::assertSame(99, $this->offset("...\nstartxref\n99\n%%EOF\n\n   "));
    }

    public function testPicksLastStartxref(): void
    {
        self::assertSame(222, $this->offset("startxref\n111\n%%EOF\nstuff\nstartxref\n222\n%%EOF\n"));
    }

    public function testMissingThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->offset("no marker here");
    }
}
