<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;

/**
 * @internal Resolved terminal AcroForm field: object number, merged
 * (inheritance-applied) dictionary, the object numbers of its widget
 * annotations, fully-qualified name, type, flags, effective /DA, options.
 */
final readonly class ResolvedField
{
    /**
     * @param list<int> $widgetObjectNumbers
     * @param list<string> $options
     */
    public function __construct(
        public int $objectNumber,
        public Dictionary $dict,
        public array $widgetObjectNumbers,
        public string $name,
        public FormFieldType $type,
        public int $flags,
        public ?string $defaultAppearance,
        public array $options,
    ) {}

    public function isReadOnly(): bool { return ($this->flags & 1) !== 0; }
    public function isMultiline(): bool { return ($this->flags & 4096) !== 0; }
    public function isMultiSelect(): bool { return ($this->flags & 2097152) !== 0; }
}
