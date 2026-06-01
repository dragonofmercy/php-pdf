<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use Closure;
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Post-serialization patcher. Locates the single /Contents placeholder, fills
 * /ByteRange with the real offsets, signs the two byte ranges, and writes the
 * signature hex into /Contents - all in place so the total byte length (and
 * therefore the xref offsets) is preserved. The signer is injectable for
 * testing; production uses Pkcs7Signer.
 */
final readonly class SignaturePatcher
{
    /** @var (Closure(string): string)|null */
    private ?Closure $injectedSigner;

    /**
     * @param (callable(string): string)|null $signer returns DER bytes for the given data; null uses Pkcs7Signer
     */
    public function __construct(?callable $signer = null)
    {
        $this->injectedSigner = $signer !== null ? Closure::fromCallable($signer) : null;
    }

    public function patch(string $bytes, Signature $sig): string
    {
        $needle = '/Contents <';
        $contentsPos = strpos($bytes, $needle);
        if ($contentsPos === false) {
            throw new PdfException('Signature /Contents placeholder not found in output');
        }
        if (strpos($bytes, $needle, $contentsPos + strlen($needle)) !== false) {
            throw new PdfException('Multiple /Contents placeholders found; single-signature only');
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
        $brPos = strpos($bytes, SignatureDictionaryEmitter::BYTERANGE_PLACEHOLDER);
        if ($brPos === false) {
            throw new PdfException('Signature /ByteRange placeholder not found in output');
        }
        $bytes = substr_replace($bytes, $byteRange, $brPos, strlen(SignatureDictionaryEmitter::BYTERANGE_PLACEHOLDER));

        $signedData = substr($bytes, 0, $lt) . substr($bytes, $gt + 1);

        $signer = $this->injectedSigner ?? function (string $data) use ($sig): string {
            $der = (new Pkcs7Signer())->sign($data, $sig->certificate);
            if ($sig->tsa !== null) {
                $der = (new SignatureTimestamper($sig->tsa->hash))->timestamp($der, $sig->tsa->client);
            }
            return $der;
        };
        $der = $signer($signedData);

        $hex = strtoupper(bin2hex($der));
        $capacity = $sig->maxSignatureBytes * 2;
        if (strlen($hex) > $capacity) {
            throw new PdfException(sprintf(
                'Signature is %d hex chars but /Contents holds %d; increase maxSignatureBytes',
                strlen($hex),
                $capacity,
            ));
        }
        $hex = str_pad($hex, $capacity, '0', STR_PAD_RIGHT);

        return substr_replace($bytes, $hex, $lt + 1, $capacity);
    }
}
