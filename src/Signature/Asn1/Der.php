<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Asn1;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Minimal DER (definite-length) ASN.1 encoder/decoder for the RFC 3161 and
 * CMS structures this library builds and edits. Not a general ASN.1 library:
 * it supports single-byte tags, definite lengths, and the specific primitives
 * the timestamp path needs.
 *
 * @internal
 */
final class Der
{
    public static function encodeLength(int $length): string
    {
        if ($length < 0) {
            throw new PdfException("DER length cannot be negative, got {$length}");
        }
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        $n = $length;
        while ($n > 0) {
            $bytes = chr($n & 0xFF) . $bytes;
            $n >>= 8;
        }
        $numLengthBytes = strlen($bytes);
        // DER long-form: at most 126 length octets (0x7E), so 0x80|n is always in [0x81, 0xFE].
        return chr((0x80 | $numLengthBytes) & 0xFF) . $bytes;
    }

    public static function tlv(int $tag, string $value): string
    {
        return chr($tag & 0xFF) . self::encodeLength(strlen($value)) . $value;
    }

    public static function sequence(string ...$parts): string
    {
        return self::tlv(0x30, implode('', $parts));
    }

    public static function set(string ...$parts): string
    {
        return self::tlv(0x31, implode('', $parts));
    }

    public static function integer(int $value): string
    {
        if ($value < 0) {
            throw new PdfException("Der::integer only encodes non-negative integers, got {$value}");
        }
        if ($value === 0) {
            return self::tlv(0x02, "\x00");
        }
        $bytes = '';
        $n = $value;
        while ($n > 0) {
            $bytes = chr($n & 0xFF) . $bytes;
            $n >>= 8;
        }
        // Prepend 0x00 if the high bit is set, to keep the integer positive.
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return self::tlv(0x02, $bytes);
    }

    /**
     * Encodes a non-negative integer given as raw big-endian magnitude bytes
     * (used for random nonces wider than PHP_INT).
     */
    public static function integerFromBytes(string $magnitude): string
    {
        $magnitude = ltrim($magnitude, "\x00");
        if ($magnitude === '') {
            $magnitude = "\x00";
        }
        if ((ord($magnitude[0]) & 0x80) !== 0) {
            $magnitude = "\x00" . $magnitude;
        }
        return self::tlv(0x02, $magnitude);
    }

    public static function boolean(bool $value): string
    {
        return self::tlv(0x01, $value ? "\xFF" : "\x00");
    }

    public static function octetString(string $bytes): string
    {
        return self::tlv(0x04, $bytes);
    }

    public static function null(): string
    {
        return "\x05\x00";
    }

    public static function oid(string $dotted): string
    {
        $arcs = array_map('intval', explode('.', $dotted));
        if (count($arcs) < 2) {
            throw new PdfException("OID must have at least two arcs, got '{$dotted}'");
        }
        $body = self::base128($arcs[0] * 40 + $arcs[1]);
        foreach (array_slice($arcs, 2) as $arc) {
            $body .= self::base128($arc);
        }
        return self::tlv(0x06, $body);
    }

    /**
     * Context-tag constructed element, e.g. [0] EXPLICIT or [1] IMPLICIT SET.
     */
    public static function contextConstructed(int $tagNumber, string $content): string
    {
        return self::tlv(0xA0 | $tagNumber, $content);
    }

    /**
     * Reads one TLV header at $offset.
     *
     * @return array{tag: int, length: int, valueStart: int, end: int}
     */
    public static function readHeader(string $data, int $offset): array
    {
        $len = strlen($data);
        if ($offset + 2 > $len) {
            throw new PdfException('DER truncated: cannot read tag and length');
        }
        $tag = ord($data[$offset]);
        $lengthByte = ord($data[$offset + 1]);
        $cursor = $offset + 2;
        if ($lengthByte === 0x80) {
            throw new PdfException('DER indefinite-length encoding is not supported');
        }
        if (($lengthByte & 0x80) === 0) {
            $length = $lengthByte;
        } else {
            $numOctets = $lengthByte & 0x7F;
            if ($cursor + $numOctets > $len) {
                throw new PdfException('DER truncated: cannot read long-form length');
            }
            $length = 0;
            for ($i = 0; $i < $numOctets; $i++) {
                $length = ($length << 8) | ord($data[$cursor + $i]);
            }
            $cursor += $numOctets;
        }
        $end = $cursor + $length;
        if ($end > $len) {
            throw new PdfException('DER truncated: declared length exceeds buffer');
        }
        return ['tag' => $tag, 'length' => $length, 'valueStart' => $cursor, 'end' => $end];
    }

    private static function base128(int $value): string
    {
        if ($value < 0x80) {
            return chr($value & 0x7F);
        }
        $out = chr($value & 0x7F);
        $value >>= 7;
        while ($value > 0) {
            $out = chr(0x80 | ($value & 0x7F)) . $out;
            $value >>= 7;
        }
        return $out;
    }
}
