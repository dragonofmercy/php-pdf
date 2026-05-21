<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Single radio button widget. Multiple `Radio` instances sharing the same
 * `$group` are emitted as one PDF `/Field` with `/Kids`. The `$value` becomes
 * the export value of this specific button (the PDF `/AS` state name).
 *
 * `name()` returns `$group` because the parent /Field's /T is the group name.
 */
final readonly class Radio implements FormField
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public string $group,
        public string $value,
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
        if ($group === '' || $value === '') {
            throw new PdfException('Radio group and value cannot be empty');
        }
    }

    public function name(): string
    {
        return $this->group;
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

    private static function formatNumber(float $v): string
    {
        if ((float) (int) $v === $v) {
            return (string) (int) $v;
        }
        return (string) $v;
    }
}
