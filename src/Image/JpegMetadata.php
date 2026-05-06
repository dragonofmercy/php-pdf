<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Parsed dimensions / component count of a baseline or progressive JPEG.
 *
 * @internal
 */
final readonly class JpegMetadata
{
    public function __construct(
        public int $width,
        public int $height,
        public int $components,
        public int $bitsPerComponent,
    ) {}

    public static function parse(string $data): self
    {
        $len = strlen($data);
        if ($len < 4 || $data[0] !== "\xFF" || $data[1] !== "\xD8") {
            throw new PdfException('JPEG missing SOI marker (corrupt file)');
        }

        $i = 2;
        while ($i + 1 < $len) {
            // Markers may be preceded by fill bytes (0xFF). Skip them.
            while ($i < $len && $data[$i] === "\xFF") {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            $marker = ord($data[$i]);
            $i++;

            // Standalone markers (no length): RST0..RST7, SOI, EOI, TEM.
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0xD8 || $marker === 0xD9 || $marker === 0x01) {
                if ($marker === 0xD9) {
                    break;
                }
                continue;
            }

            // SOF markers: FF C0..FF CF except FF C4, FF C8, FF CC.
            $isSof = $marker >= 0xC0 && $marker <= 0xCF
                && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC;

            if ($i + 1 >= $len) {
                break;
            }
            $segLen = (ord($data[$i]) << 8) | ord($data[$i + 1]);
            if ($segLen < 2 || $i + $segLen > $len) {
                throw new PdfException('JPEG segment length is malformed');
            }

            if ($isSof) {
                if ($segLen < 8) {
                    throw new PdfException('JPEG SOF segment is too short');
                }
                $bitsPerComponent = ord($data[$i + 2]);
                $height = (ord($data[$i + 3]) << 8) | ord($data[$i + 4]);
                $width = (ord($data[$i + 5]) << 8) | ord($data[$i + 6]);
                $components = ord($data[$i + 7]);
                if ($components !== 1 && $components !== 3 && $components !== 4) {
                    throw new PdfException("JPEG has unsupported component count: {$components}");
                }
                return new self(
                    width: $width,
                    height: $height,
                    components: $components,
                    bitsPerComponent: $bitsPerComponent,
                );
            }

            $i += $segLen;
        }

        throw new PdfException('JPEG missing SOF marker (corrupt file)');
    }
}
