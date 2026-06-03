<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Table;

use DragonOfMercy\PhpPdf\CellPadding;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\TextAlign;
use DragonOfMercy\PhpPdf\VerticalAlign;

/**
 * A rich table cell: either text (with optional style overrides) or an image.
 * A scalar value in a row is shorthand for Cell::of($scalar). Image cells are
 * built with Cell::image() and default to centered / middle alignment.
 */
final readonly class Cell
{
    private function __construct(
        public bool $image_,
        public string $text = '',
        public ?Image $image = null,
        public ?float $imageWidth = null,
        public ?float $imageHeight = null,
        public ?bool $bold = null,
        public ?TextAlign $align = null,
        public ?VerticalAlign $verticalAlign = null,
        public ?Color $textColor = null,
        public ?Color $fill = null,
        public ?CellPadding $padding = null,
    ) {}

    public static function of(string|\Stringable $text): self
    {
        return new self(image_: false, text: (string) $text);
    }

    public static function image(Image|string $src, ?float $w = null, ?float $h = null): self
    {
        $image = $src instanceof Image ? $src : Image::fromFile($src);
        return new self(
            image_: true,
            image: $image,
            imageWidth: $w,
            imageHeight: $h,
            align: TextAlign::CENTER,
            verticalAlign: VerticalAlign::MIDDLE,
        );
    }

    public function isText(): bool { return !$this->image_; }
    public function isImage(): bool { return $this->image_; }

    public function bold(bool $bold = true): self
    {
        return $this->copy(bold: $bold);
    }

    public function align(TextAlign $align): self
    {
        return $this->copy(align: $align);
    }

    public function verticalAlign(VerticalAlign $verticalAlign): self
    {
        return $this->copy(verticalAlign: $verticalAlign);
    }

    public function textColor(Color $c): self
    {
        return $this->copy(textColor: $c);
    }

    public function fill(Color $c): self
    {
        return $this->copy(fill: $c);
    }

    public function padding(CellPadding $p): self
    {
        return $this->copy(padding: $p);
    }

    private function copy(
        ?bool $bold = null,
        ?TextAlign $align = null,
        ?VerticalAlign $verticalAlign = null,
        ?Color $textColor = null,
        ?Color $fill = null,
        ?CellPadding $padding = null,
    ): self {
        return new self(
            image_: $this->image_,
            text: $this->text,
            image: $this->image,
            imageWidth: $this->imageWidth,
            imageHeight: $this->imageHeight,
            bold: $bold ?? $this->bold,
            align: $align ?? $this->align,
            verticalAlign: $verticalAlign ?? $this->verticalAlign,
            textColor: $textColor ?? $this->textColor,
            fill: $fill ?? $this->fill,
            padding: $padding ?? $this->padding,
        );
    }
}
