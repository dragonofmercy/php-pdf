<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Validates the RIFF/WEBP container and holds the decoded, zlib-compressed
 * sample streams ready for the embedder. Pixels are decoded eagerly at parse
 * time (same posture as {@see PngMetadata::parse()}).
 *
 * Field semantics:
 *  - $colorBytes: gzcompressed packed RGB samples (8-bit, DeviceRGB).
 *  - $alphaBytes: gzcompressed packed 8-bit opacity samples (255 = opaque) for
 *    the /SMask, or null when the source has no alpha. `$alphaBytes !== null` is
 *    the single source of truth for "this image needs an SMask object".
 *
 * @internal
 */
final readonly class WebpMetadata
{
    public function __construct(
        public int $width,
        public int $height,
        public string $colorBytes,
        public ?string $alphaBytes,
    ) {}

    public static function parse(string $data): self
    {
        if (strlen($data) < 12 || substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WEBP') {
            throw new PdfException('Invalid WebP signature (corrupt file or wrong format)');
        }

        $decoded = WebpDecoder::decode($data);

        $color = gzcompress($decoded['rgb'], 6);
        if ($color === false) {
            throw new PdfException('WebP color stream compression failed');
        }

        $alpha = null;
        if ($decoded['alpha'] !== null) {
            $alpha = gzcompress($decoded['alpha'], 6);
            if ($alpha === false) {
                throw new PdfException('WebP alpha stream compression failed');
            }
        }

        return new self($decoded['width'], $decoded['height'], $color, $alpha);
    }
}
