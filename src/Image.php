<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Image\JpegMetadata;
use DragonOfMercy\PhpPdf\Image\PngMetadata;

/**
 * Public value object representing an image to be embedded in the PDF.
 * Construct via {@see self::fromFile()} or {@see self::fromBytes()};
 * format is auto-detected from magic bytes.
 *
 * Once parsed, an Image instance is immutable and can be passed to
 * {@see Page::image()} any number of times -- the document-level
 * registry deduplicates identical instances into a single XObject.
 */
final readonly class Image
{
    private const string JPEG_MAGIC = "\xFF\xD8\xFF";
    private const string PNG_MAGIC = "\x89PNG\r\n\x1A\n";

    /**
     * @internal Public for type-narrowing in the embedder; user code should
     *           construct via {@see self::fromFile()} or {@see self::fromBytes()}.
     */
    public function __construct(
        public int $width,
        public int $height,
        public ImageFormat $format,
        public string $bytes,
        public JpegMetadata|PngMetadata $metadata,
    ) {}

    public static function fromFile(string $path): self
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new PdfException("Cannot read image file: {$path}");
        }
        return self::fromBytes($data);
    }

    public static function fromBytes(string $data): self
    {
        if (strlen($data) < 8) {
            throw new PdfException('Image data is too short to identify format');
        }

        if (str_starts_with($data, self::JPEG_MAGIC)) {
            $meta = JpegMetadata::parse($data);
            return new self(
                width: $meta->width,
                height: $meta->height,
                format: ImageFormat::JPEG,
                bytes: $data,
                metadata: $meta,
            );
        }

        if (str_starts_with($data, self::PNG_MAGIC)) {
            $meta = PngMetadata::parse($data);
            return new self(
                width: $meta->width,
                height: $meta->height,
                format: ImageFormat::PNG,
                bytes: $data,
                metadata: $meta,
            );
        }

        throw new PdfException('Unsupported image format (expected JPEG or PNG)');
    }
}
