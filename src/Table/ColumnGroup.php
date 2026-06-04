<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Table;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\TextAlign;

/**
 * One header-band cell that spans `span` adjacent columns above the per-column
 * header row. A spacer (empty label) lets the column header beneath rise to
 * fill both bands. Build with of() / spacer() and chain the style overrides.
 */
final readonly class ColumnGroup
{
    public function __construct(
        public string $label = '',
        public int $span = 1,
        public ?Color $fill = null,
        public ?Color $textColor = null,
        public ?bool $bold = null,
        public TextAlign $align = TextAlign::CENTER,
    ) {
        if ($span < 1) {
            throw new PdfException('Column group span must be >= 1, got ' . $span);
        }
    }

    public static function of(string $label, int $span = 1): self
    {
        return new self(label: $label, span: $span);
    }

    public static function spacer(int $span = 1): self
    {
        return new self(label: '', span: $span);
    }

    public function isSpacer(): bool
    {
        return $this->label === '';
    }

    public function fill(Color $c): self
    {
        return new self($this->label, $this->span, $c, $this->textColor, $this->bold, $this->align);
    }

    public function textColor(Color $c): self
    {
        return new self($this->label, $this->span, $this->fill, $c, $this->bold, $this->align);
    }

    public function bold(bool $bold = true): self
    {
        return new self($this->label, $this->span, $this->fill, $this->textColor, $bold, $this->align);
    }

    public function align(TextAlign $a): self
    {
        return new self($this->label, $this->span, $this->fill, $this->textColor, $this->bold, $a);
    }
}
