<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

/**
 * Fluent readonly value object describing a cell border. Each side can be
 * independently active, with a single global width, color, and style applied
 * to all active sides. Static factories cover the common shapes; `withX()`
 * mutators return new instances.
 */
final readonly class Border
{
    public function __construct(
        public bool $top,
        public bool $right,
        public bool $bottom,
        public bool $left,
        public ?float $width,
        public Color $color,
        public BorderStyle $style,
    ) {}

    public static function all(): self
    {
        return new self(
            top: true,
            right: true,
            bottom: true,
            left: true,
            width: 0.5,
            color: Color::rgb(0, 0, 0),
            style: BorderStyle::SOLID,
        );
    }

    public static function none(): self
    {
        return new self(
            top: false,
            right: false,
            bottom: false,
            left: false,
            width: 0.5,
            color: Color::rgb(0, 0, 0),
            style: BorderStyle::SOLID,
        );
    }

    public static function sides(
        bool $top = false,
        bool $right = false,
        bool $bottom = false,
        bool $left = false,
    ): self {
        return new self(
            top: $top,
            right: $right,
            bottom: $bottom,
            left: $left,
            width: 0.5,
            color: Color::rgb(0, 0, 0),
            style: BorderStyle::SOLID,
        );
    }

    public function withWidth(float $width): self
    {
        return new self($this->top, $this->right, $this->bottom, $this->left, $width, $this->color, $this->style);
    }

    public function withColor(Color $color): self
    {
        return new self($this->top, $this->right, $this->bottom, $this->left, $this->width, $color, $this->style);
    }

    public function withStyle(BorderStyle $style): self
    {
        return new self($this->top, $this->right, $this->bottom, $this->left, $this->width, $this->color, $style);
    }

    /**
     * @internal
     */
    public function isEmpty(): bool
    {
        return !$this->top && !$this->right && !$this->bottom && !$this->left;
    }
}
