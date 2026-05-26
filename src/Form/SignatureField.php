<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\Action\FieldActions;

/**
 * Unsigned signature form field (/FT /Sig). A placeholder the user signs later
 * in a desktop reader (Acrobat / Reader); no cryptography is performed here.
 * Build via the named constructors: visible (a signing box with an optional
 * border / background) or invisible (a zero-area field, /Rect [0 0 0 0]).
 */
final readonly class SignatureField implements FormField
{
    private function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public string $name,
        public bool $visible,
        public bool $required,
        public bool $readOnly,
        public ?string $tooltip,
        public ?FieldAppearance $appearance,
    ) {
        if ($name === '') {
            throw new PdfException('Field name cannot be empty');
        }
        if ($visible && ($width <= 0 || $height <= 0)) {
            throw new PdfException(sprintf(
                'Field width and height must be positive, got w=%s h=%s',
                self::formatNumber($width),
                self::formatNumber($height),
            ));
        }
    }

    public static function visible(
        float $x,
        float $y,
        float $width,
        float $height,
        string $name,
        bool $required = false,
        bool $readOnly = false,
        ?string $tooltip = null,
        ?FieldAppearance $appearance = null,
    ): self {
        return new self($x, $y, $width, $height, $name, true, $required, $readOnly, $tooltip, $appearance);
    }

    public static function invisible(
        string $name,
        bool $required = false,
        bool $readOnly = false,
        ?string $tooltip = null,
    ): self {
        return new self(0.0, 0.0, 0.0, 0.0, $name, false, $required, $readOnly, $tooltip, null);
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
        return null;
    }

    private static function formatNumber(float $v): string
    {
        if ((float) (int) $v === $v) {
            return (string) (int) $v;
        }
        return (string) $v;
    }
}
