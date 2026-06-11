<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Form\Fill;

/**
 * Read-only snapshot of one AcroForm field discovered in an opened PDF.
 */
final readonly class FormFieldInfo
{
    /**
     * @param string|bool|list<string>|null $value
     * @param list<string> $options
     */
    public function __construct(
        public string $name,
        public FormFieldType $type,
        public string|bool|array|null $value,
        public array $options,
        public bool $readOnly,
        public bool $required,
        public bool $multiline,
    ) {}
}
