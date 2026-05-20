<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;

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
        public bool $required = false,
        public bool $readOnly = false,
        public ?int $maxLength = null,
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
        if ($maxLength !== null && $maxLength <= 0) {
            throw new PdfException(sprintf(
                'TextField maxLength must be positive, got %d',
                $maxLength,
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
