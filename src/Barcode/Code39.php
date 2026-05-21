<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

use DragonOfMercy\PhpPdf\{Color, Page};
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Code 39 barcode (ISO/IEC 16388), native 43-character set only.
 * Each symbol is 9 elements (5 bars, 4 spaces) with exactly 3 wide elements;
 * wide:narrow ratio is fixed at 3:1. The `*` start/stop is added
 * automatically and is not a valid input character. A mod-43 check digit is
 * optional via {@see self::withCheckDigit()}.
 *
 * @internal
 */
final readonly class Code39 implements Barcode
{
    private const int QUIET_MODULES = 10;

    /**
     * Element width pattern per symbol: 9 chars of 'n'/'w'. Element order is
     * bar,space,bar,space,bar,space,bar,space,bar. ISO/IEC 16388 Annex A.
     */
    private const array PATTERNS = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];

    /** mod-43 value of each data character (no '*'). */
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
        public bool $hasCheckDigit,
    ) {}

    public static function of(string $data): self
    {
        if ($data === '') {
            throw new PdfException('Code39 input cannot be empty');
        }
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            if (!isset(self::VALUES[$data[$i]])) {
                throw new PdfException("Code39: invalid character '{$data[$i]}' at index {$i}");
            }
        }
        return new self($data, Color::rgb(0, 0, 0), true, false);
    }

    public static function ofUnchecked(string $data): self
    {
        return new self($data, Color::rgb(0, 0, 0), true, false);
    }

    public function withColor(Color $color): self
    {
        return new self($this->data, $color, $this->showText, $this->hasCheckDigit);
    }

    public function withoutText(): self
    {
        return new self($this->data, $this->color, false, $this->hasCheckDigit);
    }

    public function withCheckDigit(): self
    {
        return new self($this->data, $this->color, $this->showText, true);
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
        $sequence = '*' . $this->data;
        if ($this->hasCheckDigit) {
            $sum = 0;
            $len = strlen($this->data);
            for ($i = 0; $i < $len; $i++) {
                $sum += self::VALUES[$this->data[$i]];
            }
            $checkValue = $sum % 43;
            $checkChar = array_search($checkValue, self::VALUES, true);
            // VALUES is a bijection over 0..42 and $checkValue = $sum % 43, so this is always a string.
            assert(is_string($checkChar));
            $sequence .= $checkChar;
        }
        $sequence .= '*';

        $modules = [];
        $chars = str_split($sequence);
        $last = count($chars) - 1;
        foreach ($chars as $idx => $ch) {
            $pattern = self::PATTERNS[$ch];
            for ($e = 0; $e < 9; $e++) {
                $isBar = $e % 2 === 0;
                $width = $pattern[$e] === 'w' ? 3 : 1;
                for ($k = 0; $k < $width; $k++) {
                    $modules[] = $isBar;
                }
            }
            if ($idx !== $last) {
                $modules[] = false;
            }
        }
        return $modules;
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
            'Code39',
        );
    }
}
