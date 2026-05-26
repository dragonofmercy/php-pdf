<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

use DragonOfMercy\PhpPdf\Form\Action\FieldActions;

/**
 * Marker interface for AcroForm field value objects. The single `name()`
 * getter is used by {@see \DragonOfMercy\PhpPdf\Form\AcroFormEmitter} to
 * validate global uniqueness of field names (with the documented exception
 * that all `Radio` widgets sharing the same `$group` are emitted as one
 * parent `/Field` and therefore share the same `name()`).
 *
 * `dimensions()` returns the widget's rectangle in the document's user unit
 * (top-down Y), used by the emitter to compute the bottom-up `/Rect`.
 *
 * `appearance()` returns the optional visual customization (border, background,
 * text color, font, size, alignment). Null means "use the PDF default".
 *
 * `actions()` returns the optional JavaScript actions attached to this field's
 * widget (emitted as /AA). Null means no additional actions.
 */
interface FormField
{
    public function name(): string;

    /**
     * @return array{x: float, y: float, width: float, height: float}
     */
    public function dimensions(): array;

    public function appearance(): ?FieldAppearance;

    /**
     * Optional JavaScript actions attached to this field's widget (emitted as
     * /AA). Null means no additional actions. Value triggers (K/F/V/C) are only
     * legal on text-like fields; this is enforced at emission time.
     */
    public function actions(): ?FieldActions;
}
