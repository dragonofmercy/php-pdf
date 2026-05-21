<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\AztecEc;
use PHPUnit\Framework\TestCase;

final class AztecEcTest extends TestCase
{
    public function testAllPresetsExposeRedundancyPercent(): void
    {
        self::assertSame(10, AztecEc::LOW->redundancyPercent());
        self::assertSame(23, AztecEc::MEDIUM->redundancyPercent());
        self::assertSame(36, AztecEc::HIGH->redundancyPercent());
        self::assertSame(50, AztecEc::MAX->redundancyPercent());
    }

    public function testCasesAreOrderedFromLowestToHighestRedundancy(): void
    {
        $previous = 0;
        foreach (AztecEc::cases() as $case) {
            self::assertGreaterThan($previous, $case->redundancyPercent());
            $previous = $case->redundancyPercent();
        }
    }
}
