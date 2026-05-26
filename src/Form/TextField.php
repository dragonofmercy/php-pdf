<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\Action\FieldActions;

/**
 * Single-line or multi-line text input AcroForm field. Width and height are
 * in the document's user unit (top-down Y, same convention as `Page::cell`).
 * Validation is eager in the constructor.
 */
final readonly class TextField implements FormField
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public string $name,
        public string $value = '',
        public bool $multiline = false,
        public bool $password = false,
        public bool $required = false,
        public bool $readOnly = false,
        public ?int $maxLength = null,
        public ?string $tooltip = null,
        public ?FieldAppearance $appearance = null,
        public ?FieldActions $actions = null,
        public ?string $defaultValue = null,
    ) {
        if ($width <= 0 || $height <= 0) {
            throw new PdfException(sprintf(
                'Field width and height must be positive, got w=%s h=%s',
                self::formatNumber($width),
                self::formatNumber($height),
            ));
        }
        if ($name === '') {
            throw new PdfException('Field name cannot be empty');
        }
        if ($maxLength !== null && $maxLength <= 0) {
            throw new PdfException(sprintf(
                'TextField maxLength must be positive, got %d',
                $maxLength,
            ));
        }
        if ($password && $multiline) {
            throw new PdfException('TextField password field cannot be multiline');
        }
        if ($password && $value !== '') {
            throw new PdfException('TextField password field cannot have a default value');
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array{x: float, y: float, width: float, height: float}
     */
    public function dimensions(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'width' => $this->width, 'height' => $this->height];
    }

    public function appearance(): ?FieldAppearance
    {
        return $this->appearance;
    }

    public function actions(): ?FieldActions
    {
        return $this->actions;
    }

    private static function formatNumber(float $v): string
    {
        if ((float) (int) $v === $v) {
            return (string) (int) $v;
        }
        return (string) $v;
    }
}
