<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Action;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Calculate helper for a field's C trigger. Aggregate variants wrap Adobe's
 * AFSimple_Calculate over a list of source field names; custom() takes verbatim
 * JavaScript. Field names are not checked against the declared form - a missing
 * name simply yields no value at run time, matching Adobe behaviour.
 */
final readonly class Calculate
{
    private function __construct(private string $js) {}

    /** @param list<string> $fields */
    public static function sum(array $fields): self { return self::aggregate('SUM', $fields); }

    /** @param list<string> $fields */
    public static function product(array $fields): self { return self::aggregate('PRD', $fields); }

    /** @param list<string> $fields */
    public static function average(array $fields): self { return self::aggregate('AVG', $fields); }

    /** @param list<string> $fields */
    public static function min(array $fields): self { return self::aggregate('MIN', $fields); }

    /** @param list<string> $fields */
    public static function max(array $fields): self { return self::aggregate('MAX', $fields); }

    public static function custom(string $js): self
    {
        if ($js === '') {
            throw new PdfException('Calculate::custom JavaScript cannot be empty');
        }
        return new self($js);
    }

    public function js(): string
    {
        return $this->js;
    }

    /** @param list<string> $fields */
    private static function aggregate(string $op, array $fields): self
    {
        if ($fields === []) {
            throw new PdfException('Calculate requires at least one field name');
        }
        $quoted = [];
        foreach ($fields as $name) {
            if ($name === '') {
                throw new PdfException('Calculate field names must be non-empty strings');
            }
            $quoted[] = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"';
        }
        return new self(sprintf('AFSimple_Calculate("%s", new Array(%s));', $op, implode(', ', $quoted)));
    }
}
