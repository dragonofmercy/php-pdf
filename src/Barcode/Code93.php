<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

use DragonOfMercy\PhpPdf\{Color, Page};
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Code 93 barcode. User input is restricted to the 43 printable characters
 * shared with Code 39. The value table holds 47 entries (values 43-46 are
 * full-ASCII shift symbols, never user input, but produced as checksum
 * results so their patterns must exist). Each symbol is 9 modules; no
 * inter-character gap. Two mandatory check characters (C, then K) are
 * appended before the stop. A single termination bar follows the stop.
 *
 * @internal
 */
final readonly class Code93 implements OrientableBarcode
{
    use Orientable;
    private const int QUIET_MODULES = 10;

    /** 9-module pattern per value 0-46 plus start/stop, '1' = dark module. */
    private const array PATTERNS = [
        0 => '100010100', 1 => '101001000', 2 => '101000100', 3 => '101000010',
        4 => '100101000', 5 => '100100100', 6 => '100100010', 7 => '101010000',
        8 => '100010010', 9 => '100001010', 10 => '110101000', 11 => '110100100',
        12 => '110100010', 13 => '110010100', 14 => '110010010', 15 => '110001010',
        16 => '101101000', 17 => '101100100', 18 => '101100010', 19 => '100110100',
        20 => '100011010', 21 => '101011000', 22 => '101001100', 23 => '101000110',
        24 => '100101100', 25 => '100010110', 26 => '110110100', 27 => '110110010',
        28 => '110101100', 29 => '110100110', 30 => '110010110', 31 => '110011010',
        32 => '101101100', 33 => '101100110', 34 => '100110110', 35 => '100111010',
        36 => '100101110', 37 => '111010100', 38 => '111010010', 39 => '111001010',
        40 => '101101110', 41 => '101110110', 42 => '110101110', 43 => '100100110',
        44 => '111011010', 45 => '111010110', 46 => '100110010',
    ];

    private const string START_STOP = '101011110';

    /** Printable input characters mapped to their mod-47 value. */
    private const array VALUES = [
        '0' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7,
        '8' => 8, '9' => 9, 'A' => 10, 'B' => 11, 'C' => 12, 'D' => 13, 'E' => 14,
        'F' => 15, 'G' => 16, 'H' => 17, 'I' => 18, 'J' => 19, 'K' => 20, 'L' => 21,
        'M' => 22, 'N' => 23, 'O' => 24, 'P' => 25, 'Q' => 26, 'R' => 27, 'S' => 28,
        'T' => 29, 'U' => 30, 'V' => 31, 'W' => 32, 'X' => 33, 'Y' => 34, 'Z' => 35,
        '-' => 36, '.' => 37, ' ' => 38, '$' => 39, '/' => 40, '+' => 41, '%' => 42,
    ];

    private function __construct(
        public string $data,
        public Color $color,
        public bool $showText,
        public Orientation $orientation = Orientation::Horizontal,
    ) {}

    public static function of(string $data): self
    {
        if ($data === '') {
            throw new PdfException('Code93 input cannot be empty');
        }
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            if (!isset(self::VALUES[$data[$i]])) {
                throw new PdfException("Code93: invalid character '{$data[$i]}' at index {$i}");
            }
        }
        return new self($data, Color::rgb(0, 0, 0), true);
    }

    public static function ofUnchecked(string $data): self
    {
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

    /**
     * @internal Test access.
     * @return list<bool>
     */
    public function encodeModulesForTest(): array
    {
        return $this->encodeModules();
    }

    /**
     * @return list<bool>
     */
    private function encodeModules(): array
    {
        $len = strlen($this->data);
        $values = [];
        for ($i = 0; $i < $len; $i++) {
            $values[] = self::VALUES[$this->data[$i]];
        }

        $values[] = self::weightedCheck($values, 20); // C check character (spec max weight 20)
        $values[] = self::weightedCheck($values, 15); // K check character, weighted over values incl. C (spec max weight 15)

        $modules = [];
        self::appendPattern($modules, self::START_STOP);
        foreach ($values as $value) {
            self::appendPattern($modules, self::PATTERNS[$value]);
        }
        self::appendPattern($modules, self::START_STOP);
        $modules[] = true; // termination bar: single dark module after the stop symbol
        return $modules;
    }

    /**
     * Weighted mod-47 checksum: weights cycle 1..$maxWeight from the
     * right-most value leftward. The modulus 47 is the Code 93 alphabet
     * size; C uses maxWeight 20 and K uses maxWeight 15 (spec requirement).
     *
     * @param list<int> $values
     */
    private static function weightedCheck(array $values, int $maxWeight): int
    {
        $sum = 0;
        $weight = 1;
        for ($i = count($values) - 1; $i >= 0; $i--) {
            $sum += $values[$i] * $weight;
            $weight++;
            if ($weight > $maxWeight) {
                $weight = 1;
            }
        }
        return $sum % 47;
    }

    /**
     * @param list<bool> $modules
     */
    private static function appendPattern(array &$modules, string $pattern): void
    {
        $n = strlen($pattern);
        for ($i = 0; $i < $n; $i++) {
            $modules[] = $pattern[$i] === '1';
        }
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
                fontSizeModule: 0.0,
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
        $modules = $this->encodeModules();
        Linear1d::draw(
            $page,
            $x,
            $y,
            $w,
            $h,
            $modules,
            self::QUIET_MODULES,
            $this->color,
            $this->showText ? $this->data : null,
            'Code93',
            orientation: $this->orientation,
        );
    }
}
