<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Qr;

use DragonOfMercy\PhpPdf\Barcode\Qr\Matrix;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Validates the `Matrix::ALIGNMENT_POSITIONS` data against ISO 18004 invariants
 * that can be derived independently from the table.
 */
final class AlignmentPositionsTest extends TestCase
{
    /**
     * @return array<int, list<int>>
     */
    private function positions(): array
    {
        $ref = new ReflectionClass(Matrix::class);
        /** @var array<int, list<int>> $positions */
        $positions = $ref->getConstant('ALIGNMENT_POSITIONS');
        return $positions;
    }

    public function testAllVersionsArePresent(): void
    {
        $positions = $this->positions();
        for ($v = 1; $v <= 40; $v++) {
            self::assertArrayHasKey($v, $positions, "Missing alignment positions for V{$v}");
        }
    }

    public function testV1HasNoAlignmentPositions(): void
    {
        $positions = $this->positions();
        self::assertSame([], $positions[1]);
    }

    public function testAlignmentPositionCountMatchesIsoFormula(): void
    {
        // ISO 18004: number of alignment pattern coordinates per side
        // = floor(V / 7) + 2 for V >= 2 (i.e. the table grows by one row
        // every seven versions: V2-V6 = 2, V7-V13 = 3, V14-V20 = 4, ...).
        $positions = $this->positions();
        for ($v = 2; $v <= 40; $v++) {
            $expectedCount = intdiv($v, 7) + 2;
            self::assertCount(
                $expectedCount,
                $positions[$v],
                "V{$v}: expected {$expectedCount} positions, got " . count($positions[$v]),
            );
        }
    }

    public function testFirstPositionIsAlwaysSix(): void
    {
        $positions = $this->positions();
        for ($v = 2; $v <= 40; $v++) {
            self::assertSame(6, $positions[$v][0], "V{$v}: first position must be 6");
        }
    }

    public function testLastPositionMatchesMatrixSizeMinusSeven(): void
    {
        $positions = $this->positions();
        for ($v = 2; $v <= 40; $v++) {
            $size = 17 + 4 * $v;
            $expectedLast = $size - 7;
            $actualLast = $positions[$v][count($positions[$v]) - 1];
            self::assertSame(
                $expectedLast,
                $actualLast,
                "V{$v}: last position must be size-7 = {$expectedLast}, got {$actualLast}",
            );
        }
    }

    public function testPositionsAreStrictlyMonotonic(): void
    {
        $positions = $this->positions();
        for ($v = 2; $v <= 40; $v++) {
            $previous = -1;
            foreach ($positions[$v] as $p) {
                self::assertGreaterThan(
                    $previous,
                    $p,
                    "V{$v}: positions must be strictly increasing",
                );
                $previous = $p;
            }
        }
    }

    public function testAllPositionsAreEven(): void
    {
        // ISO 18004 Annex E: every alignment coordinate is even (the fixed
        // first coordinate is 6).
        $positions = $this->positions();
        for ($v = 2; $v <= 40; $v++) {
            foreach ($positions[$v] as $p) {
                self::assertSame(
                    0,
                    $p % 2,
                    "V{$v}: alignment coordinate {$p} must be even",
                );
            }
        }
    }

    public function testIntermediateSpacingIsConstantAndEven(): void
    {
        // ISO 18004 Annex E: for versions with 3+ alignment coordinates, every
        // gap from the second coordinate onward is identical and even. Only the
        // first gap (from the fixed coordinate 6 to the second) may differ.
        // A single transcription typo in any intermediate coordinate breaks
        // this constant-step invariant, which the count/first/last/monotonic
        // checks cannot detect.
        $positions = $this->positions();
        for ($v = 2; $v <= 40; $v++) {
            $row = $positions[$v];
            $n = count($row);
            if ($n < 3) {
                continue; // 2-coordinate rows have no intermediate step
            }
            $step = $row[2] - $row[1];
            self::assertSame(
                0,
                $step % 2,
                "V{$v}: alignment step {$step} must be even",
            );
            for ($i = 3; $i < $n; $i++) {
                self::assertSame(
                    $step,
                    $row[$i] - $row[$i - 1],
                    "V{$v}: gap between positions {$i} and " . ($i - 1)
                        . " ({$row[$i]} - {$row[$i - 1]}) must equal the constant step {$step}",
                );
            }
        }
    }
}
