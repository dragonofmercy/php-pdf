<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Writer\Object;

/**
 * PDF null object (PDF 1.7 7.3.9). Emitted as the keyword `null`.
 *
 * @internal
 */
final class PdfNull implements PdfObject
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function toBytes(): string
    {
        return 'null';
    }
}
