<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\Action\FieldActions;

/**
 * Clickable push-button AcroForm field. Width and height are in the document's
 * user unit (top-down Y, same convention as Page::cell). Carries a
 * {@see ButtonAction} and holds no value. Validation is eager.
 */
final readonly class PushButton implements FormField
{
    // No $required property: push buttons carry no value and do not participate in form validation.
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public string $name,
        public string $caption,
        public ButtonAction $action,
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
    }

    public static function of(
        float $x,
        float $y,
        float $width,
        float $height,
        string $name,
        string $caption,
        ButtonAction $action,
        bool $readOnly = false,
        ?string $tooltip = null,
        ?FieldAppearance $appearance = null,
        ?FieldActions $actions = null,
    ): self {
        return new self($x, $y, $width, $height, $name, $caption, $action, $readOnly, $tooltip, $appearance, actions: $actions);
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
