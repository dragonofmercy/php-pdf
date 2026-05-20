<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Multi-line list AcroForm field, single or multi-select. `$options` follows
 * the same two-shape contract as `Combobox`. `$value` is null (no selection),
 * a string (single), or list<string> (multi). Cross-validation against
 * `$options` is deferred to `Document::output()`.
 *
 * If `multiSelect` is false, a list of length > 1 is rejected at
 * `Document::output()`; a list of length 1 is treated as the single string,
 * and a plain string with multiSelect=true is treated as a single-element list.
 */
final readonly class Listbox implements FormField
{
    /**
     * @param list<string>|array<string, string> $options
     * @param string|list<string>|null           $value
     */
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public string $name,
        public array $options,
        public string|array|null $value = null,
        public bool $multiSelect = false,
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
        if ($options === []) {
            throw new PdfException(sprintf(
                "Listbox options list cannot be empty for field '%s'",
                $name,
            ));
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    private static function formatNumber(float $v): string
    {
        if ((float) (int) $v === $v) {
            return (string) (int) $v;
        }
        return (string) $v;
    }
}
