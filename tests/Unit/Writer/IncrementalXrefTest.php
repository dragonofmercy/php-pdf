<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Writer;

use DragonOfMercy\PhpPdf\Writer\IncrementalXref;
use PHPUnit\Framework\TestCase;

final class IncrementalXrefTest extends TestCase
{
    public function testContiguousObjectsAreOneSubsection(): void
    {
        $xref = new IncrementalXref();
        $xref->recordOffset(7, 100);
        $xref->recordOffset(8, 200);
        $out = $xref->toBytes();
        self::assertSame(
            "xref\n7 2\n0000000100 00000 n \n0000000200 00000 n \n",
            $out,
        );
    }

    public function testNonContiguousObjectsSplitIntoSubsections(): void
    {
        $xref = new IncrementalXref();
        $xref->recordOffset(1, 50);
        $xref->recordOffset(7, 100);
        $xref->recordOffset(8, 200);
        $out = $xref->toBytes();
        self::assertSame(
            "xref\n1 1\n0000000050 00000 n \n7 2\n0000000100 00000 n \n0000000200 00000 n \n",
            $out,
        );
    }
}
