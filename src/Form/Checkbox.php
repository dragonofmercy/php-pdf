<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * On/off checkbox AcroForm field. Width and height in user unit. Two AP
 * streams are emitted for /On and /Off (handled by AcroFormEmitter +
 * CheckboxAppearance).
 */
final readonly class Checkbox implements FormField
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public string $name,
        public bool $checked = false,
        public bool $required = false,
        public bool $readOnly = false,
        public ?string $tooltip = null,
        public ?FieldAppearance $appearance = null,
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

    private static function formatNumber(float $v): string
    {
        if ((float) (int) $v === $v) {
            return (string) (int) $v;
        }
        return (string) $v;
    }
}
