<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Action;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Display-format helper for a text-like field. Each variant maps to an Adobe
 * AFxxx keystroke/format pair: keystrokeJs() goes on the field's K trigger and
 * formatJs() on its F trigger. Only Adobe's JavaScript engine (Acrobat/Reader)
 * executes these; browser viewers ignore them.
 */
final readonly class Format
{
    /** @var array<string, int> */
    private const array TIME_PATTERNS = [
        'HH:MM' => 0,
        'h:MM tt' => 1,
        'HH:MM:ss' => 2,
        'h:MM:ss tt' => 3,
    ];

    private function __construct(
        private string $keystrokeJs,
        private string $formatJs,
    ) {
    }

    public static function number(int $decimals = 2, bool $thousands = true, int $negStyle = 0): self
    {
        self::assertDecimals($decimals);
        self::assertNegStyle($negStyle);
        $sep = $thousands ? 0 : 1;
        $args = sprintf('%d, %d, %d, 0, "", false', $decimals, $sep, $negStyle);
        return new self("AFNumber_Keystroke({$args});", "AFNumber_Format({$args});");
    }

    public static function currency(string $symbol, int $decimals = 2, bool $prepend = false): self
    {
        self::assertDecimals($decimals);
        if ($symbol === '') {
            throw new PdfException('Format::currency symbol cannot be empty');
        }
        $strCurrency = $prepend ? $symbol . ' ' : ' ' . $symbol;
        $bool = $prepend ? 'true' : 'false';
        $args = sprintf('%d, 0, 0, 0, "%s", %s', $decimals, self::escapeJsString($strCurrency), $bool);
        return new self("AFNumber_Keystroke({$args});", "AFNumber_Format({$args});");
    }

    public static function percent(int $decimals = 2, bool $thousands = false): self
    {
        self::assertDecimals($decimals);
        $sep = $thousands ? 0 : 1;
        $args = sprintf('%d, %d', $decimals, $sep);
        return new self("AFPercent_Keystroke({$args});", "AFPercent_Format({$args});");
    }

    public static function date(string $pattern = 'mm/dd/yyyy'): self
    {
        if ($pattern === '') {
            throw new PdfException('Format::date pattern cannot be empty');
        }
        $p = self::escapeJsString($pattern);
        return new self("AFDate_KeystrokeEx(\"{$p}\");", "AFDate_FormatEx(\"{$p}\");");
    }

    public static function time(string $pattern = 'HH:MM'): self
    {
        if (!isset(self::TIME_PATTERNS[$pattern])) {
            throw new PdfException(sprintf(
                'Format::time pattern "%s" is not a known Adobe time format; use Format::custom for an arbitrary pattern',
                $pattern,
            ));
        }
        $ptf = self::TIME_PATTERNS[$pattern];
        return new self("AFTime_Keystroke({$ptf});", "AFTime_Format({$ptf});");
    }

    public static function custom(string $keystrokeJs, string $formatJs): self
    {
        if ($keystrokeJs === '' || $formatJs === '') {
            throw new PdfException('Format::custom keystroke and format JavaScript cannot be empty');
        }
        return new self($keystrokeJs, $formatJs);
    }

    public function keystrokeJs(): string
    {
        return $this->keystrokeJs;
    }

    public function formatJs(): string
    {
        return $this->formatJs;
    }

    private static function assertDecimals(int $decimals): void
    {
        if ($decimals < 0) {
            throw new PdfException(sprintf('Format decimals cannot be negative, got %d', $decimals));
        }
    }

    private static function assertNegStyle(int $negStyle): void
    {
        if ($negStyle < 0 || $negStyle > 3) {
            throw new PdfException(sprintf('Format number negStyle must be 0-3, got %d', $negStyle));
        }
    }

    private static function escapeJsString(string $s): string
    {
        // Escapes backslash and double-quote only. Inputs are short currency
        // symbols and date/time patterns that never contain control characters.
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $s);
    }
}
