<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

use DragonOfMercy\PhpPdf\{Color, Font, Page};
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Code 128 (ISO/IEC 15417). Variable-length, supports ASCII 0-127 with
 * automatic switching between sets A, B, C to minimise total width.
 *
 * Default rendering: black bars + human-readable text below.
 */
final readonly class Code128 implements OrientableBarcode
{
    use Orientable;

    private function __construct(
        public string $data,
        public Color $color,
        public bool $showText,
        public Orientation $orientation = Orientation::Horizontal,
    ) {}

    public static function of(string $data): self
    {
        if ($data === '') {
            throw new PdfException('Code 128 data must not be empty');
        }
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $byte = ord($data[$i]);
            if ($byte > 127) {
                throw new PdfException(sprintf(
                    "Code 128: character at position %d (codepoint %d '%s') is not in the supported ASCII 0-127 range",
                    $i,
                    $byte,
                    mb_chr($byte, 'UTF-8') ?: '?',
                ));
            }
        }
        return new self($data, Color::rgb(0, 0, 0), true);
    }

    public function withColor(Color $color): self
    {
        return new self($this->data, $color, $this->showText, $this->orientation);
    }

    public function withoutText(): self
    {
        return new self($this->data, $this->color, false, $this->orientation);
    }

    public function withOrientation(Orientation $orientation): self
    {
        return new self($this->data, $this->color, $this->showText, $orientation);
    }

    public function widthForModule(float $moduleSize): float
    {
        if ($moduleSize <= 0) {
            throw new PdfException("widthForModule expects a positive module size, got {$moduleSize}");
        }
        $modules = $this->encodeModules();
        return (count($modules) + 2 * self::QUIET_MODULES) * $moduleSize;
    }

    public function encode(): EncodedBarcode
    {
        $modules = $this->encodeModules();
        $padded = array_merge(
            array_fill(0, self::QUIET_MODULES, false),
            $modules,
            array_fill(0, self::QUIET_MODULES, false),
        );
        $total = count($padded);

        $segments = [];
        if ($this->showText && $this->data !== '') {
            $segments[] = new HumanTextSegment(
                text: $this->data,
                xModule: $total / 2.0,
                yModule: 0.0,
                fontSizeModule: 1.5,
                anchor: TextAnchor::MIDDLE,
            );
        }

        return new EncodedBarcode(
            kind: BarcodeKind::LINEAR_1D,
            modules: $padded,
            humanTextSegments: $segments,
            color: $this->color,
            orientation: $this->orientation,
        );
    }

    public function draw(Page $page, float $x, float $y, float $w, ?float $h): void
    {
        if ($h === null) {
            throw new PdfException('Code128 requires explicit h (height)');
        }
        $unit = $page->unit;
        $xPt = $unit->toPoints($x);
        $yPt = $unit->toPoints($y);
        $wPt = $unit->toPoints($w);
        $hPt = $unit->toPoints($h);

        Renderer::oriented($page, $this->orientation, $xPt, $yPt, $wPt, $hPt, function () use ($page, $xPt, $yPt, $wPt, $hPt): void {
            $modules = $this->encodeModules();
            // Quiet zone QUIET_MODULES on each side per ISO 15417.
            $totalModules = count($modules) + 2 * self::QUIET_MODULES;
            $moduleW = $wPt / $totalModules;
            // 85% of h goes to bars, 15% to human-readable text below.
            $barsHeight = $hPt * 0.85;
            $textHeight = $hPt - $barsHeight;

            $padded = array_merge(
                array_fill(0, self::QUIET_MODULES, false),
                $modules,
                array_fill(0, self::QUIET_MODULES, false),
            );
            $body = Renderer::runLengthRow($padded, $xPt, $yPt, $moduleW, $barsHeight);
            $page->contentStream()->append(Renderer::wrap($body, $this->color));

            if ($this->showText) {
                // Font sized to fit the data; minimum 8pt cap-height-ish, max 12pt.
                // Also capped at textHeight so cap-height (~70% of fontSize) keeps
                // a visible gap above the glyphs - otherwise long data + small h
                // makes the text overlap the bars.
                $fontSize = min(12.0, $wPt / max(strlen($this->data), 8) * 0.8, $textHeight);
                // Baseline: centre of text band + half cap-height (approx fontSize * 0.35).
                $textY = $yPt + $barsHeight + $textHeight / 2 + $fontSize * 0.35;
                $textYUnit = $page->unit->fromPoints($textY);

                $page->save();
                $page->setFillColor($this->color);
                $page->setFont(Font::helvetica(), $fontSize);
                $textWidth = $page->stringWidth($this->data);
                $textX = $page->unit->fromPoints($xPt + $wPt / 2) - $textWidth / 2;
                $page->text($textX, $textYUnit, $this->data);
                $page->restore();
            }
        });
    }

    private const int QUIET_MODULES = 10;

    /**
     * Code 128 pattern table per ISO/IEC 15417 Annex A.
     * Indexed 0..106. Each entry is 6 width integers (sum = 11) representing
     * alternating bar/space widths starting with a bar.
     *
     * @var list<list<int>>
     */
    private const array PATTERNS = [
        [2, 1, 2, 2, 2, 2], // 0
        [2, 2, 2, 1, 2, 2], // 1
        [2, 2, 2, 2, 2, 1], // 2
        [1, 2, 1, 2, 2, 3], // 3
        [1, 2, 1, 3, 2, 2], // 4
        [1, 3, 1, 2, 2, 2], // 5
        [1, 2, 2, 2, 1, 3], // 6
        [1, 2, 2, 3, 1, 2], // 7
        [1, 3, 2, 2, 1, 2], // 8
        [2, 2, 1, 2, 1, 3], // 9
        [2, 2, 1, 3, 1, 2], // 10
        [2, 3, 1, 2, 1, 2], // 11
        [1, 1, 2, 2, 3, 2], // 12
        [1, 2, 2, 1, 3, 2], // 13
        [1, 2, 2, 2, 3, 1], // 14
        [1, 1, 3, 2, 2, 2], // 15
        [1, 2, 3, 1, 2, 2], // 16
        [1, 2, 3, 2, 2, 1], // 17
        [2, 2, 3, 2, 1, 1], // 18
        [2, 2, 1, 1, 3, 2], // 19
        [2, 2, 1, 2, 3, 1], // 20
        [2, 1, 3, 2, 1, 2], // 21
        [2, 2, 3, 1, 1, 2], // 22
        [3, 1, 2, 1, 3, 1], // 23
        [3, 1, 1, 2, 2, 2], // 24
        [3, 2, 1, 1, 2, 2], // 25
        [3, 2, 1, 2, 2, 1], // 26
        [3, 1, 2, 2, 1, 2], // 27
        [3, 2, 2, 1, 1, 2], // 28
        [3, 2, 2, 2, 1, 1], // 29
        [2, 1, 2, 1, 2, 3], // 30
        [2, 1, 2, 3, 2, 1], // 31
        [2, 3, 2, 1, 2, 1], // 32
        [1, 1, 1, 3, 2, 3], // 33
        [1, 3, 1, 1, 2, 3], // 34
        [1, 3, 1, 3, 2, 1], // 35
        [1, 1, 2, 3, 1, 3], // 36
        [1, 3, 2, 1, 1, 3], // 37
        [1, 3, 2, 3, 1, 1], // 38
        [2, 1, 1, 3, 1, 3], // 39
        [2, 3, 1, 1, 1, 3], // 40
        [2, 3, 1, 3, 1, 1], // 41
        [1, 1, 2, 1, 3, 3], // 42
        [1, 1, 2, 3, 3, 1], // 43
        [1, 3, 2, 1, 3, 1], // 44
        [1, 1, 3, 1, 2, 3], // 45
        [1, 1, 3, 3, 2, 1], // 46
        [1, 3, 3, 1, 2, 1], // 47
        [3, 1, 3, 1, 2, 1], // 48
        [2, 1, 1, 3, 3, 1], // 49
        [2, 3, 1, 1, 3, 1], // 50
        [2, 1, 3, 1, 1, 3], // 51
        [2, 1, 3, 3, 1, 1], // 52
        [2, 1, 3, 1, 3, 1], // 53
        [3, 1, 1, 1, 2, 3], // 54
        [3, 1, 1, 3, 2, 1], // 55
        [3, 3, 1, 1, 2, 1], // 56
        [3, 1, 2, 1, 1, 3], // 57
        [3, 1, 2, 3, 1, 1], // 58
        [3, 3, 2, 1, 1, 1], // 59
        [3, 1, 4, 1, 1, 1], // 60
        [2, 2, 1, 4, 1, 1], // 61
        [4, 3, 1, 1, 1, 1], // 62
        [1, 1, 1, 2, 2, 4], // 63
        [1, 1, 1, 4, 2, 2], // 64
        [1, 2, 1, 1, 2, 4], // 65
        [1, 2, 1, 4, 2, 1], // 66
        [1, 4, 1, 1, 2, 2], // 67
        [1, 4, 1, 2, 2, 1], // 68
        [1, 1, 2, 2, 1, 4], // 69
        [1, 1, 2, 4, 1, 2], // 70
        [1, 2, 2, 1, 1, 4], // 71
        [1, 2, 2, 4, 1, 1], // 72
        [1, 4, 2, 1, 1, 2], // 73
        [1, 4, 2, 2, 1, 1], // 74
        [2, 4, 1, 2, 1, 1], // 75
        [2, 2, 1, 1, 1, 4], // 76
        [4, 1, 3, 1, 1, 1], // 77
        [2, 4, 1, 1, 1, 2], // 78
        [1, 3, 4, 1, 1, 1], // 79
        [1, 1, 1, 2, 4, 2], // 80
        [1, 2, 1, 1, 4, 2], // 81
        [1, 2, 1, 2, 4, 1], // 82
        [1, 1, 4, 2, 1, 2], // 83
        [1, 2, 4, 1, 1, 2], // 84
        [1, 2, 4, 2, 1, 1], // 85
        [4, 1, 1, 2, 1, 2], // 86
        [4, 2, 1, 1, 1, 2], // 87
        [4, 2, 1, 2, 1, 1], // 88
        [2, 1, 2, 1, 4, 1], // 89
        [2, 1, 4, 1, 2, 1], // 90
        [4, 1, 2, 1, 2, 1], // 91
        [1, 1, 1, 1, 4, 3], // 92
        [1, 1, 1, 3, 4, 1], // 93
        [1, 3, 1, 1, 4, 1], // 94
        [1, 1, 4, 1, 1, 3], // 95
        [1, 1, 4, 3, 1, 1], // 96
        [4, 1, 1, 1, 1, 3], // 97
        [4, 1, 1, 3, 1, 1], // 98
        [1, 1, 3, 1, 4, 1], // 99
        [1, 1, 4, 1, 3, 1], // 100
        [3, 1, 1, 1, 4, 1], // 101
        [4, 1, 1, 1, 3, 1], // 102
        [2, 1, 1, 4, 1, 2], // 103 StartA
        [2, 1, 1, 2, 1, 4], // 104 StartB
        [2, 1, 1, 2, 3, 2], // 105 StartC
        [2, 3, 3, 1, 1, 1], // 106
    ];

    private const int CODE_C  = 99;    // switch to set C
    private const int CODE_B  = 100;   // switch to set B
    private const int CODE_A  = 101;   // switch to set A
    private const int START_A = 103;
    private const int START_B = 104;
    private const int START_C = 105;
    private const int STOP    = 106;
    private const int CHECKSUM_MODULUS = 103; // same numeric value as START_A but different role per ISO 15417

    /** Stop pattern: 13 modules (4 bars + 3 spaces + final bar). */
    /** @var list<int> */
    private const array STOP_PATTERN = [2, 3, 3, 1, 1, 1, 2];

    /**
     * @internal Test access.
     * @return list<int>
     */
    public function encodeValuesForTest(): array
    {
        return $this->encodeValues();
    }

    /**
     * @internal Test access.
     * @return list<bool>
     */
    public function encodeModulesForTest(): array
    {
        return $this->encodeModules();
    }

    /**
     * Compute the value sequence: [start, ...data, checksum, stop].
     *
     * @return list<int>
     */
    private function encodeValues(): array
    {
        [$start, $values] = self::pickStartAndEncode($this->data);

        // Checksum: (start + sum(pos=1..) pos * value_pos) mod 103
        $checksum = $start;
        foreach ($values as $i => $v) {
            $checksum += ($i + 1) * $v;
        }
        $checksum %= self::CHECKSUM_MODULUS;

        return array_merge([$start], $values, [$checksum, self::STOP]);
    }

    /**
     * Pick start set + encode the body.
     *
     *  - StartC if 4+ leading digits (or exactly 2 digits total).
     *  - StartA if first char is a control char (0-31).
     *  - StartB otherwise.
     *  - In B/A: switch to C if 6+ digits ahead (or 4+ trailing digits at end).
     *  - In C: switch to B when no digit pair available.
     *  - In B: switch to A on a control char.
     *  - In A: switch to B on lowercase.
     *
     * @return array{0: int, 1: list<int>}
     */
    private static function pickStartAndEncode(string $data): array
    {
        $len = strlen($data);
        $values = [];
        $i = 0;

        if (self::startsWithDigits($data, 0, $len === 2 ? 2 : 4)) {
            $start = self::START_C;
            $set = 'C';
        } elseif ($len > 0 && ord($data[0]) < 32) {
            $start = self::START_A;
            $set = 'A';
        } else {
            $start = self::START_B;
            $set = 'B';
        }

        while ($i < $len) {
            if ($set === 'C') {
                if ($i + 1 < $len && ctype_digit($data[$i]) && ctype_digit($data[$i + 1])) {
                    $values[] = (int) substr($data, $i, 2);
                    $i += 2;
                    continue;
                }
                // switch back to B
                $values[] = self::CODE_B;
                $set = 'B';
                continue;
            }
            if ($set === 'B') {
                $byte = ord($data[$i]);
                if ($byte < 32) {
                    $values[] = self::CODE_A;
                    $set = 'A';
                    continue;
                }
                // switch to C if 6+ digits ahead, or 4+ trailing digits at end
                if (self::startsWithDigits($data, $i, 6)
                    || ($i + 4 === $len && self::startsWithDigits($data, $i, 4))
                ) {
                    $values[] = self::CODE_C;
                    $set = 'C';
                    continue;
                }
                $values[] = $byte - 32;
                $i++;
                continue;
            }
            // set === 'A'
            $byte = ord($data[$i]);
            if ($byte >= 96 && $byte <= 127) {
                $values[] = self::CODE_B;
                $set = 'B';
                continue;
            }
            if (self::startsWithDigits($data, $i, 6)) {
                $values[] = self::CODE_C;
                $set = 'C';
                continue;
            }
            $values[] = $byte < 32 ? $byte + 64 : $byte - 32;
            $i++;
        }

        return [$start, $values];
    }

    private static function startsWithDigits(string $s, int $offset, int $count): bool
    {
        if ($count <= 0 || $offset + $count > strlen($s)) {
            return false;
        }
        for ($k = 0; $k < $count; $k++) {
            if (!ctype_digit($s[$offset + $k])) {
                return false;
            }
        }
        return true;
    }

    /** @return list<bool> */
    private function encodeModules(): array
    {
        $values = $this->encodeValues();
        $modules = [];
        $lastIdx = count($values) - 1;
        foreach ($values as $idx => $v) {
            if ($idx === $lastIdx) {
                // Stop pattern (13 modules: 4 bars + 3 spaces + final bar).
                $isBar = true;
                foreach (self::STOP_PATTERN as $width) {
                    for ($k = 0; $k < $width; $k++) {
                        $modules[] = $isBar;
                    }
                    $isBar = !$isBar;
                }
                continue;
            }
            $widths = self::PATTERNS[$v];
            $isBar = true;
            foreach ($widths as $width) {
                for ($k = 0; $k < $width; $k++) {
                    $modules[] = $isBar;
                }
                $isBar = !$isBar;
            }
        }
        return $modules;
    }
}
