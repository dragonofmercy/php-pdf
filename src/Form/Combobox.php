<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\Action\FieldActions;

/**
 * Combobox (dropdown) AcroForm field. `$options` accepts two shapes:
 *
 *   list<string>           ['France', 'Suisse', 'Belgique']
 *   array<string, string>  ['fr' => 'France', 'ch' => 'Suisse']  (export => label)
 *
 * The shape is detected at emission time by AcroFormEmitter via the keys
 * (numeric-only -> list ; at least one string key -> map). `$value` is the
 * initial selection: an option label (list shape) or an export key (map
 * shape). Verified at `Document::output()` time.
 */
final readonly class Combobox implements FormField
{
    /**
     * @param list<string>|array<string, string> $options
     */
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public string $name,
        public array $options,
        public ?string $value = null,
        public bool $editable = false,
        public bool $required = false,
        public bool $readOnly = false,
        public ?string $tooltip = null,
        public ?FieldAppearance $appearance = null,
        public ?FieldActions $actions = null,
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
        if ($options === []) {
            throw new PdfException(sprintf(
                "Combobox options list cannot be empty for field '%s'",
                $name,
            ));
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
