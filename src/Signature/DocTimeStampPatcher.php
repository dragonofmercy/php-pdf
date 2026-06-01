<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Post-serialization patcher for the /DocTimeStamp added in an incremental
 * revision. Finds the timestamp /Contents placeholder at or after $searchFrom
 * (the start of the appended revision, so a filled revision-1 signature is
 * never matched), fills /ByteRange with the real offsets, requests an RFC 3161
 * token over the two byte ranges, and writes the token hex into /Contents - all
 * in place so the total length (and the appended xref offsets) is preserved.
 */
final readonly class DocTimeStampPatcher
{
    public function patch(string $bytes, Tsa $tsa, int $maxSignatureBytes, int $searchFrom): string
    {
        $needle = '/Contents <';
        $contentsPos = strpos($bytes, $needle, $searchFrom);
        if ($contentsPos === false) {
            throw new PdfException('DocTimeStamp /Contents placeholder not found in the appended revision');
        }
        $lt = strpos($bytes, '<', $contentsPos);
        if ($lt === false) {
            throw new PdfException('Malformed DocTimeStamp /Contents placeholder');
        }
        $gt = strpos($bytes, '>', $lt);
        if ($gt === false) {
            throw new PdfException('Unterminated DocTimeStamp /Contents placeholder');
        }
        $len = strlen($bytes);

        $byteRange = sprintf('[0 %010d %010d %010d]', $lt, $gt + 1, $len - ($gt + 1));
        if (strlen($byteRange) !== strlen(SignatureDictionaryEmitter::BYTERANGE_PLACEHOLDER)) {
            throw new PdfException('Computed DocTimeStamp /ByteRange exceeds the reserved placeholder width');
        }
        $brPos = strpos($bytes, SignatureDictionaryEmitter::BYTERANGE_PLACEHOLDER, $searchFrom);
        if ($brPos === false) {
            throw new PdfException('DocTimeStamp /ByteRange placeholder not found in the appended revision');
        }
        $bytes = substr_replace($bytes, $byteRange, $brPos, strlen(SignatureDictionaryEmitter::BYTERANGE_PLACEHOLDER));

        $signedData = substr($bytes, 0, $lt) . substr($bytes, $gt + 1);
        $imprint = $tsa->hash->digest($signedData);
        $token = $tsa->client->timestamp($imprint, $tsa->hash->oid());

        $hex = strtoupper(bin2hex($token));
        $capacity = $maxSignatureBytes * 2;
        if (strlen($hex) > $capacity) {
            throw new PdfException(sprintf(
                'DocTimeStamp token is %d hex chars but /Contents holds %d; increase maxSignatureBytes',
                strlen($hex),
                $capacity,
            ));
        }
        $hex = str_pad($hex, $capacity, '0', STR_PAD_RIGHT);

        return substr_replace($bytes, $hex, $lt + 1, $capacity);
    }
}
