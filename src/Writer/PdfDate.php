<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Writer;

use DateTimeImmutable;

/**
 * Formats a DateTimeImmutable as a PDF date string (ISO 32000-1 7.9.4):
 * "D:YYYYMMDDHHmmSS" with a trailing "Z" for UTC or "+HH'mm" / "-HH'mm".
 */
final class PdfDate
{
    public static function format(DateTimeImmutable $date): string
    {
        $base = $date->format('\D\:YmdHis');
        $offset = $date->getOffset();
        if ($offset === 0) {
            return $base . 'Z';
        }
        $sign = $offset >= 0 ? '+' : '-';
        $h = intdiv(abs($offset), 3600);
        $m = intdiv(abs($offset) % 3600, 60);
        return $base . sprintf("%s%02d'%02d", $sign, $h, $m);
    }
}
