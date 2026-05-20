<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

/**
 * Marker interface for AcroForm field value objects. The single `name()`
 * getter is used by {@see \DragonOfMercy\PhpPdf\Form\AcroFormEmitter} to
 * validate global uniqueness of field names (with the documented exception
 * that all `Radio` widgets sharing the same `$group` are emitted as one
 * parent `/Field` and therefore share the same `name()`).
 *
 * `dimensions()` returns the widget's rectangle in the document's user unit
 * (top-down Y), used by the emitter to compute the bottom-up `/Rect`.
 */
interface FormField
{
    public function name(): string;

    /**
     * @return array{x: float, y: float, width: float, height: float}
     */
    public function dimensions(): array;
}
