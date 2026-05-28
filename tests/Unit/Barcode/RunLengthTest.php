<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\RunLength;
use PHPUnit\Framework\TestCase;

final class RunLengthTest extends TestCase
{
    public function testEmptyRow(): void
    {
        self::assertSame([], RunLength::runLengths([]));
    }

    public function testAllFalse(): void
    {
        self::assertSame([], RunLength::runLengths([false, false, false]));
    }

    public function testSingleTrue(): void
    {
        self::assertSame([[0, 1]], RunLength::runLengths([true]));
    }

    public function testSingleRun(): void
    {
        self::assertSame([[1, 3]], RunLength::runLengths([false, true, true, true, false]));
    }

    public function testMultipleRuns(): void
    {
        self::assertSame(
            [[0, 2], [4, 1], [7, 3]],
            RunLength::runLengths([true, true, false, false, true, false, false, true, true, true]),
        );
    }

    public function testRunAtEnd(): void
    {
        self::assertSame([[2, 3]], RunLength::runLengths([false, false, true, true, true]));
    }
}
