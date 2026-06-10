<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader\Filter;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;

/**
 * ASCII85Decode (PDF 1.7 7.4.3).
 *
 * @internal
 */
final readonly class Ascii85Decoder
{
    public static function decode(string $data): string
    {
        if (str_starts_with($data, '<~')) {
            $data = substr($data, 2);
        }
        $end = strpos($data, '~>');
        if ($end !== false) {
            $data = substr($data, 0, $end);
        }
        $out = '';
        $group = [];
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $byte = $data[$i];
            if (str_contains("\x00\t\n\x0C\r ", $byte)) {
                continue;
            }
            if ($byte === 'z' && $group === []) {
                $out .= "\x00\x00\x00\x00";
                continue;
            }
            $code = ord($byte) - 33;
            if ($code < 0 || $code > 84) {
                throw new PdfParseException(sprintf('Invalid ASCII85 character 0x%02X at position %d', ord($byte), $i));
            }
            $group[] = $code;
            if (count($group) === 5) {
                $out .= self::decodeGroup($group, 4);
                $group = [];
            }
        }
        $remaining = count($group);
        if ($remaining === 1) {
            throw new PdfParseException('ASCII85 data ends with a single-character group');
        }
        if ($remaining > 1) {
            for ($i = $remaining; $i < 5; $i++) {
                $group[] = 84; // pad with 'u'
            }
            $out .= self::decodeGroup($group, $remaining - 1);
        }
        return $out;
    }

    /** @param list<int> $group */
    private static function decodeGroup(array $group, int $bytes): string
    {
        $value = 0;
        foreach ($group as $code) {
            $value = $value * 85 + $code;
        }
        if ($value > 0xFFFFFFFF) {
            throw new PdfParseException('ASCII85 group exceeds 32-bit range');
        }
        $decoded = pack('N', $value);
        return substr($decoded, 0, $bytes);
    }
}
