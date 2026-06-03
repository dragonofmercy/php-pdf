<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Table;

use DragonOfMercy\PhpPdf\CellPadding;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\TextAlign;
use DragonOfMercy\PhpPdf\VerticalAlign;

/**
 * Defines one column of a table: which row key it reads, its header label,
 * its width policy (fixed or fill), and its default cell alignment / padding.
 */
final readonly class Column
{
    public function __construct(
        public string $key,
        public ?string $header = null,
        public ?float $fixedWidth = null,
        public ?int $fillWeight = null,
        public TextAlign $align = TextAlign::LEFT,
        public VerticalAlign $verticalAlign = VerticalAlign::TOP,
        public ?CellPadding $padding = null,
    ) {}

    public static function of(string $key, ?string $header = null): self
    {
        return new self(key: $key, header: $header);
    }

    public function width(float $w): self
    {
        if ($this->fillWeight !== null) {
            throw new PdfException('Column "' . $this->key . '" cannot set both width and fill');
        }
        if ($w <= 0) {
            throw new PdfException('Column "' . $this->key . '" width must be positive, got ' . $w);
        }
        return new self($this->key, $this->header, $w, null, $this->align, $this->verticalAlign, $this->padding);
    }

    public function fill(int $weight = 1): self
    {
        if ($this->fixedWidth !== null) {
            throw new PdfException('Column "' . $this->key . '" cannot set both width and fill');
        }
        if ($weight <= 0) {
            throw new PdfException('Column "' . $this->key . '" fill weight must be positive, got ' . $weight);
        }
        return new self($this->key, $this->header, null, $weight, $this->align, $this->verticalAlign, $this->padding);
    }

    public function align(TextAlign $align): self
    {
        return new self($this->key, $this->header, $this->fixedWidth, $this->fillWeight, $align, $this->verticalAlign, $this->padding);
    }

    public function verticalAlign(VerticalAlign $verticalAlign): self
    {
        return new self($this->key, $this->header, $this->fixedWidth, $this->fillWeight, $this->align, $verticalAlign, $this->padding);
    }

    public function padding(CellPadding $padding): self
    {
        return new self($this->key, $this->header, $this->fixedWidth, $this->fillWeight, $this->align, $this->verticalAlign, $padding);
    }
}
