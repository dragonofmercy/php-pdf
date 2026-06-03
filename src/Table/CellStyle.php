<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Table;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\TextAlign;

/**
 * Optional per-cell style overrides. Returned by the TableStyle conditional
 * callback and used internally as the resolved-style carrier. A null field
 * means "no override; inherit from the lower-precedence layer".
 */
final readonly class CellStyle
{
    public function __construct(
        public ?Color $textColor = null,
        public ?Color $fill = null,
        public ?bool $bold = null,
        public ?TextAlign $align = null,
    ) {}

    public static function new(): self
    {
        return new self();
    }

    public function withTextColor(Color $c): self
    {
        return new self($c, $this->fill, $this->bold, $this->align);
    }

    public function withFill(Color $c): self
    {
        return new self($this->textColor, $c, $this->bold, $this->align);
    }

    public function withBold(bool $bold): self
    {
        return new self($this->textColor, $this->fill, $bold, $this->align);
    }

    public function withAlign(TextAlign $a): self
    {
        return new self($this->textColor, $this->fill, $this->bold, $a);
    }
}
