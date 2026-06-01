<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Asn1;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Parses an RFC 3161 TimeStampResp: validates PKIStatus and returns the
 * timeStampToken (a CMS ContentInfo / SignedData) verbatim.
 *
 * @internal
 */
final class TimeStampRespParser
{
    private const string ID_SIGNED_DATA = '1.2.840.113549.1.7.2';

    public static function extractToken(string $resp): string
    {
        $outer = Der::readHeader($resp, 0);
        if ($outer['tag'] !== 0x30) {
            throw new PdfException('TimeStampResp is not a SEQUENCE');
        }

        // PKIStatusInfo ::= SEQUENCE { status INTEGER, ... }
        $statusInfo = Der::readHeader($resp, $outer['valueStart']);
        if ($statusInfo['tag'] !== 0x30) {
            throw new PdfException('TimeStampResp status is not a SEQUENCE');
        }
        $statusInt = Der::readHeader($resp, $statusInfo['valueStart']);
        if ($statusInt['tag'] !== 0x02) {
            throw new PdfException('PKIStatusInfo.status is not an INTEGER');
        }
        $status = self::readInt($resp, $statusInt);
        if ($status !== 0 && $status !== 1) {
            throw new PdfException("TSA rejected the request: PKIStatus status {$status}");
        }

        // timeStampToken ContentInfo OPTIONAL is the next sibling after PKIStatusInfo.
        if ($statusInfo['end'] >= $outer['end']) {
            throw new PdfException('TimeStampResp granted but contains no timeStampToken');
        }
        $token = Der::readHeader($resp, $statusInfo['end']);
        if ($token['tag'] !== 0x30) {
            throw new PdfException('timeStampToken is not a ContentInfo SEQUENCE');
        }
        $contentType = Der::readHeader($resp, $token['valueStart']);
        if ($contentType['tag'] !== 0x06) {
            throw new PdfException('timeStampToken contentType is not an OID');
        }
        $oid = self::readOid($resp, $contentType);
        if ($oid !== self::ID_SIGNED_DATA) {
            throw new PdfException("timeStampToken is not CMS SignedData (got OID {$oid})");
        }

        // The token bytes are the full ContentInfo TLV, from its tag at
        // $statusInfo['end'] through $token['end'].
        return substr($resp, $statusInfo['end'], $token['end'] - $statusInfo['end']);
    }

    /** @param array{tag: int, length: int, valueStart: int, end: int} $header */
    private static function readInt(string $data, array $header): int
    {
        $value = 0;
        for ($i = 0; $i < $header['length']; $i++) {
            $value = ($value << 8) | ord($data[$header['valueStart'] + $i]);
        }
        return $value;
    }

    /** @param array{tag: int, length: int, valueStart: int, end: int} $header */
    private static function readOid(string $data, array $header): string
    {
        $bytes = substr($data, $header['valueStart'], $header['length']);
        $first = ord($bytes[0]);
        $arcs = [intdiv($first, 40), $first % 40];
        $acc = 0;
        for ($i = 1; $i < strlen($bytes); $i++) {
            $b = ord($bytes[$i]);
            $acc = ($acc << 7) | ($b & 0x7F);
            if (($b & 0x80) === 0) {
                $arcs[] = $acc;
                $acc = 0;
            }
        }
        return implode('.', $arcs);
    }
}
