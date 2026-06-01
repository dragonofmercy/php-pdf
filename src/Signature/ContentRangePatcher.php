<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use Closure;
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Shared post-serialization /Contents byte-surgery for signatures and document
 * timestamps. Locates the /Contents placeholder at or after $searchFrom, fills
 * /ByteRange with the real offsets, hands the signed byte range to $sign, and
 * writes the returned DER (hex, padded) into /Contents - all in place so the
 * total byte length (and any xref offsets) is preserved.
 */
final readonly class ContentRangePatcher
{
    /**
     * @param int $searchFrom byte offset to start the placeholder search (0 for
     *   the base revision; the appended-revision start otherwise)
     * @param int $capacity /Contents hex capacity (maxSignatureBytes * 2)
     * @param Closure(string): string $sign receives the signed-range bytes, returns the DER to embed
     */
    public function patch(string $bytes, int $searchFrom, int $capacity, Closure $sign): string
    {
        $needle = '/Contents <';
        $contentsPos = strpos($bytes, $needle, $searchFrom);
        if ($contentsPos === false) {
            throw new PdfException('/Contents placeholder not found');
        }
        $lt = strpos($bytes, '<', $contentsPos);
        if ($lt === false) {
            throw new PdfException('Malformed /Contents placeholder');
        }
        $gt = strpos($bytes, '>', $lt);
        if ($gt === false) {
            throw new PdfException('Unterminated /Contents placeholder');
        }
        $len = strlen($bytes);

        $byteRange = sprintf('[0 %010d %010d %010d]', $lt, $gt + 1, $len - ($gt + 1));
        if (strlen($byteRange) !== strlen(SignatureDictionaryEmitter::BYTERANGE_PLACEHOLDER)) {
            throw new PdfException('Computed /ByteRange exceeds the reserved placeholder width');
        }
        $brPos = strpos($bytes, SignatureDictionaryEmitter::BYTERANGE_PLACEHOLDER, $searchFrom);
        if ($brPos === false) {
            throw new PdfException('/ByteRange placeholder not found');
        }
        $bytes = substr_replace($bytes, $byteRange, $brPos, strlen(SignatureDictionaryEmitter::BYTERANGE_PLACEHOLDER));

        $signedData = substr($bytes, 0, $lt) . substr($bytes, $gt + 1);
        $der = $sign($signedData);

        $hex = strtoupper(bin2hex($der));
        if (strlen($hex) > $capacity) {
            throw new PdfException(sprintf(
                '/Contents payload is %d hex chars but the placeholder holds %d; increase maxSignatureBytes',
                strlen($hex),
                $capacity,
            ));
        }
        $hex = str_pad($hex, $capacity, '0', STR_PAD_RIGHT);

        return substr_replace($bytes, $hex, $lt + 1, $capacity);
    }
}
