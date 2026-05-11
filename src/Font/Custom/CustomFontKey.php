<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Identifies a single custom-TTF variant in the document-level FontRegistry:
 * an alias (registered by the user via Document::registerFontFamily) plus the
 * PostScriptName of the resolved TTF file. Two Font instances pointing to the
 * same physical TTF produce equal CustomFontKey instances and therefore share
 * a single /F<n> entry in the PDF.
 *
 * @internal
 */
final readonly class CustomFontKey
{
    public function __construct(
        public string $alias,
        public string $psName,
    ) {
        if ($alias === '' || $psName === '') {
            throw new PdfException('CustomFontKey alias and psName cannot be empty');
        }
    }

    public function toRegistryKey(): string
    {
        return $this->alias . ':' . $this->psName;
    }

    public static function fromRegistryKey(string $key): self
    {
        $sep = strpos($key, ':');
        if ($sep === false) {
            throw new PdfException("Invalid custom font registry key: '$key'");
        }
        return new self(substr($key, 0, $sep), substr($key, $sep + 1));
    }

    public function equals(self $other): bool
    {
        return $this->alias === $other->alias && $this->psName === $other->psName;
    }
}
