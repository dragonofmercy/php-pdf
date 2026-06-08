<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Text\Bidi;

use DragonOfMercy\PhpPdf\Text\Bidi\BidiAlgorithm;
use DragonOfMercy\PhpPdf\Text\Bidi\BidiCharacterType;
use PHPUnit\Framework\TestCase;

/**
 * Authoritative conformance suite: validates BidiAlgorithm against the official
 * Unicode BidiCharacterTest.txt (15.1.0). Each in-scope line provides an input
 * sequence, a paragraph direction, the resolved embedding levels, and the
 * non-removed characters in visual order. We compare resolved levels 1:1 and the
 * visual index order produced by replicating reorderLine's L1+L2 on indices.
 *
 * Phase A scope: explicit format controls, the supplementary plane, removed (X9)
 * characters, BN, and auto paragraph direction are skipped (see test docblock).
 */
final class BidiConformanceTest extends TestCase
{
    /** Explicit embedding/override/isolate format controls (UAX #9 X-rules). */
    private const array EXPLICIT_FORMAT = [
        0x202A, 0x202B, 0x202C, 0x202D, 0x202E,
        0x2066, 0x2067, 0x2068, 0x2069,
    ];

    public function testConformanceAgainstUnicodeBidiCharacterTest(): void
    {
        $path = __DIR__ . '/../../../Golden/assets/BidiCharacterTest.txt';
        self::assertFileExists($path);
        $handle = fopen($path, 'rb');
        self::assertIsResource($handle);

        $checked = 0;
        $totalFailures = 0;
        /** @var list<string> $failures */
        $failures = [];
        $lineNo = 0;

        while (($raw = fgets($handle)) !== false) {
            $lineNo++;
            $line = trim($raw);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $fields = explode(';', $line);
            if (count($fields) < 5) {
                continue;
            }

            $paraDir = (int) $fields[1];
            // Skip auto paragraph direction (covered by BidiProcessor tests).
            if ($paraDir === 2) {
                continue;
            }

            /** @var list<int> $cps */
            $cps = [];
            $hasExplicitOrSupplementaryOrBn = false;
            foreach (preg_split('/\s+/', trim($fields[0])) ?: [] as $hex) {
                if ($hex === '') {
                    continue;
                }
                $cp = (int) hexdec($hex);
                $cps[] = $cp;
                if (in_array($cp, self::EXPLICIT_FORMAT, true)) {
                    $hasExplicitOrSupplementaryOrBn = true;
                }
                if ($cp > 0xFFFF) {
                    $hasExplicitOrSupplementaryOrBn = true;
                }
                if (BidiCharacterType::of($cp) === 'BN') {
                    $hasExplicitOrSupplementaryOrBn = true;
                }
            }
            if ($hasExplicitOrSupplementaryOrBn || $cps === []) {
                continue;
            }

            // Field3: resolved levels; 'x' marks an X9-removed character.
            $levelTokens = preg_split('/\s+/', trim($fields[3])) ?: [];
            $hasRemoved = false;
            foreach ($levelTokens as $tok) {
                if ($tok === 'x') {
                    $hasRemoved = true;
                    break;
                }
            }
            if ($hasRemoved) {
                continue;
            }

            // Field4: non-removed characters in visual order (input indices).
            /** @var list<int> $expectedOrder */
            $expectedOrder = [];
            foreach (preg_split('/\s+/', trim($fields[4])) ?: [] as $tok) {
                if ($tok === '') {
                    continue;
                }
                $expectedOrder[] = (int) $tok;
            }

            /** @var list<int> $expectedLevels */
            $expectedLevels = [];
            foreach ($levelTokens as $tok) {
                $expectedLevels[] = (int) $tok;
            }

            $checked++;

            $gotLevels = BidiAlgorithm::resolveLevels($cps, $paraDir);
            $gotOrder = self::visualIndexOrder($cps, $gotLevels, $paraDir);

            if ($gotLevels !== $expectedLevels || $gotOrder !== $expectedOrder) {
                $totalFailures++;
                if (count($failures) < 20) {
                    $failures[] = sprintf(
                        "line %d: cps=[%s] para=%d\n  levels exp=[%s] got=[%s]\n  order  exp=[%s] got=[%s]",
                        $lineNo,
                        implode(' ', array_map(static fn (int $c): string => sprintf('%04X', $c), $cps)),
                        $paraDir,
                        implode(' ', $expectedLevels),
                        implode(' ', $gotLevels),
                        implode(' ', $expectedOrder),
                        implode(' ', $gotOrder),
                    );
                }
            }
        }

        fclose($handle);

        self::assertGreaterThan(
            50000,
            $checked,
            'Sanity: a substantial in-scope subset must be exercised (actual is ~91592).',
        );
        self::assertSame(
            [],
            $failures,
            sprintf(
                "%d in-scope conformance case(s) failed (showing up to 20):\n%s",
                $totalFailures,
                implode("\n", $failures),
            ),
        );
    }

    /**
     * Reorder an index array [0..n-1] by replicating reorderLine's L1 (reset
     * trailing WS/B/S to the paragraph level) and L2 (reverse runs from the
     * highest level down to the lowest odd level). Returns the input indices in
     * visual order, which is directly comparable to BidiCharacterTest field 4.
     *
     * @param list<int> $cps
     * @param list<int> $levels
     * @return list<int>
     */
    private static function visualIndexOrder(array $cps, array $levels, int $paragraphLevel): array
    {
        $n = count($cps);
        if ($n === 0) {
            return [];
        }

        // L1: reset trailing whitespace/separators to the paragraph level.
        $resetFrom = $n;
        for ($i = $n - 1; $i >= 0; $i--) {
            $type = BidiCharacterType::of($cps[$i]);
            if ($type === 'WS' || $type === 'B' || $type === 'S') {
                $resetFrom = $i;
            } else {
                break;
            }
        }
        for ($i = $resetFrom; $i < $n; $i++) {
            $levels[$i] = $paragraphLevel;
        }

        // L2: reverse runs from the highest level down to the lowest odd level.
        $order = range(0, $n - 1);
        $maxLevel = $paragraphLevel;
        foreach ($levels as $lvl) {
            if ($lvl > $maxLevel) {
                $maxLevel = $lvl;
            }
        }
        $minOdd = $maxLevel + 1;
        foreach ($levels as $lvl) {
            if ($lvl % 2 === 1 && $lvl < $minOdd) {
                $minOdd = $lvl;
            }
        }
        for ($lvl = $maxLevel; $lvl >= $minOdd; $lvl--) {
            $i = 0;
            while ($i < $n) {
                if ($levels[$order[$i]] >= $lvl) {
                    $start = $i;
                    while ($i < $n && $levels[$order[$i]] >= $lvl) {
                        $i++;
                    }
                    $slice = array_reverse(array_slice($order, $start, $i - $start));
                    array_splice($order, $start, count($slice), $slice);
                } else {
                    $i++;
                }
            }
        }

        return $order;
    }
}
