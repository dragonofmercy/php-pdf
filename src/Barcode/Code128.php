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
final readonly class Code128 implements Barcode
{
    private function __construct(
        public string $data,
        public Color $color,
        public bool $showText,
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
        return new self($this->data, $color, $this->showText);
    }

    public function withoutText(): self
    {
        return new self($this->data, $this->color, false);
    }

    public function draw(Page $page, float $x, float $y, float $w, ?float $h): void
    {
        throw new PdfException('Code128::draw() not yet implemented (Task 12)');
    }
}
