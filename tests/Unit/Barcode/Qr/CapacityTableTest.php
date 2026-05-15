<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Qr;

use DragonOfMercy\PhpPdf\Barcode\Qr\Encoder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Validates the `Encoder::CAPACITY_TABLE` data against ISO/IEC 18004 invariants
 * that can be derived independently from the table (so any transcription typo
 * is caught without comparing the table to itself).
 */
final class CapacityTableTest extends TestCase
{
    /**
     * @return array<int, array<int, array{int, list<array{int, int, int}>}>>
     */
    private function table(): array
    {
        $ref = new ReflectionClass(Encoder::class);
        /** @var array<int, array<int, array{int, list<array{int, int, int}>}>> $table */
        $table = $ref->getConstant('CAPACITY_TABLE');
        return $table;
    }

    public function testAllVersionsArePresent(): void
    {
        $table = $this->table();
        for ($v = 1; $v <= 40; $v++) {
            self::assertArrayHasKey($v, $table, "Missing capacity entry for V{$v}");
            foreach ([0, 1, 2, 3] as $ec) {
                self::assertArrayHasKey($ec, $table[$v], "Missing EC level {$ec} for V{$v}");
            }
        }
    }

    public function testTotalCodewordsMatchSumOfBlocks(): void
    {
        // For each (V, ec): totalDataCodewords MUST equal sum(blockCount * dataPerBlock).
        $table = $this->table();
        foreach ($table as $version => $byEc) {
            foreach ($byEc as $ec => [$total, $blocks]) {
                $sum = 0;
                foreach ($blocks as [$count, $dataPerBlock, $ecPerBlock]) {
                    $sum += $count * $dataPerBlock;
                }
                self::assertSame(
                    $total,
                    $sum,
                    "V{$version} EC index {$ec}: total {$total} != sum(blockCount*dataPerBlock) {$sum}",
                );
            }
        }
    }

    public function testEcPerBlockIsConstantWithinVersionEcCombination(): void
    {
        // ISO 18004: a single (V, ec) row has the same ecPerBlock for every block group.
        $table = $this->table();
        foreach ($table as $version => $byEc) {
            foreach ($byEc as $ec => [, $blocks]) {
                $ecValues = [];
                foreach ($blocks as [, , $ecPerBlock]) {
                    $ecValues[] = $ecPerBlock;
                }
                self::assertCount(
                    1,
                    array_unique($ecValues),
                    "V{$version} EC index {$ec}: ecPerBlock not constant across block groups",
                );
            }
        }
    }

    public function testDataCodewordsStrictlyDecreaseAcrossEcLevels(): void
    {
        // ISO invariant: stronger EC = less user data. L > M > Q > H for every version.
        $table = $this->table();
        foreach ($table as $version => $byEc) {
            $l = $byEc[0][0];
            $m = $byEc[1][0];
            $q = $byEc[2][0];
            $h = $byEc[3][0];
            self::assertGreaterThan($m, $l, "V{$version}: L ({$l}) not > M ({$m})");
            self::assertGreaterThan($q, $m, "V{$version}: M ({$m}) not > Q ({$q})");
            self::assertGreaterThan($h, $q, "V{$version}: Q ({$q}) not > H ({$h})");
        }
    }

    public function testTotalCodewordsMatchGeometricCapacity(): void
    {
        // Independent geometric formula for total codewords (data + EC) per version,
        // derived from ISO 18004 Table 1 (matrix capacity in bits / 8).
        // For each (V, ec): sum(blockCount * (dataPerBlock + ecPerBlock)) MUST equal
        // the version's geometric codeword count.
        $expectedTotalCodewords = self::iso18004TotalCodewords();
        $table = $this->table();
        foreach ($table as $version => $byEc) {
            foreach ($byEc as $ec => [, $blocks]) {
                $sum = 0;
                foreach ($blocks as [$count, $dataPerBlock, $ecPerBlock]) {
                    $sum += $count * ($dataPerBlock + $ecPerBlock);
                }
                self::assertSame(
                    $expectedTotalCodewords[$version],
                    $sum,
                    "V{$version} EC index {$ec}: data+EC codewords ({$sum}) != geometric total ({$expectedTotalCodewords[$version]})",
                );
            }
        }
    }

    /**
     * Geometric total codewords per version, ISO 18004 Table 1.
     *
     * @return array<int, int>
     */
    private static function iso18004TotalCodewords(): array
    {
        return [
            1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134,
            6 => 172, 7 => 196, 8 => 242, 9 => 292, 10 => 346,
            11 => 404, 12 => 466, 13 => 532, 14 => 581, 15 => 655,
            16 => 733, 17 => 815, 18 => 901, 19 => 991, 20 => 1085,
            21 => 1156, 22 => 1258, 23 => 1364, 24 => 1474, 25 => 1588,
            26 => 1706, 27 => 1828, 28 => 1921, 29 => 2051, 30 => 2185,
            31 => 2323, 32 => 2465, 33 => 2611, 34 => 2761, 35 => 2876,
            36 => 3034, 37 => 3196, 38 => 3362, 39 => 3532, 40 => 3706,
        ];
    }
}
