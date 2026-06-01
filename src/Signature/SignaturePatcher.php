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
        $first = strpos($bytes, $needle);
        if ($first === false) {
            throw new PdfException('Signature /Contents placeholder not found in output');
        }
        if (strpos($bytes, $needle, $first + strlen($needle)) !== false) {
            throw new PdfException('Multiple /Contents placeholders found; single-signature only');
        }

        $signer = $this->injectedSigner ?? function (string $data) use ($sig): string {
            $der = (new Pkcs7Signer())->sign($data, $sig->certificate);
            if ($sig->tsa !== null) {
                $der = (new SignatureTimestamper($sig->tsa->hash))->timestamp($der, $sig->tsa->client);
            }
            return $der;
        };

        return (new ContentRangePatcher())->patch($bytes, 0, $sig->maxSignatureBytes * 2, $signer);
    }
}
