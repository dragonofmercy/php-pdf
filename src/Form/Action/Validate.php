<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Action;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Validate helper for a field's V trigger. range() wraps Adobe AFRange_Validate;
 * custom() takes verbatim JavaScript that should set event.rc to false to reject.
 */
final readonly class Validate
{
    private function __construct(private string $js) {}

    public static function range(?float $min, ?float $max): self
    {
        if ($min === null && $max === null) {
            throw new PdfException('Validate::range requires at least one of min or max');
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw new PdfException(sprintf(
                'Validate::range min (%s) cannot exceed max (%s)',
                self::num($min),
                self::num($max),
            ));
        }
        $bGreater = $min !== null ? 'true' : 'false';
        $bLess = $max !== null ? 'true' : 'false';
        return new self(sprintf(
            'AFRange_Validate(%s, %s, %s, %s);',
            $bGreater,
            self::num($min ?? 0.0),
            $bLess,
            self::num($max ?? 0.0),
        ));
    }

    public static function custom(string $js): self
    {
        if ($js === '') {
            throw new PdfException('Validate::custom JavaScript cannot be empty');
        }
        return new self($js);
    }

    public function js(): string
    {
        return $this->js;
    }

    private static function num(float $v): string
    {
        if ((float) (int) $v === $v) {
            return (string) (int) $v;
        }
        return (string) $v;
    }
}
