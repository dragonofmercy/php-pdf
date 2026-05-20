<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

/**
 * Marker interface for AcroForm field value objects. The single `name()`
 * getter is used by {@see \DragonOfMercy\PhpPdf\Form\AcroFormEmitter} to
 * validate global uniqueness of field names (with the documented exception
 * that all `Radio` widgets sharing the same `$group` are emitted as one
 * parent `/Field` and therefore share the same `name()`).
 */
interface FormField
{
    public function name(): string;
}
