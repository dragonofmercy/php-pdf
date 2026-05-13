<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Image\JpegMetadata;
use DragonOfMercy\PhpPdf\Image\PngMetadata;
use DragonOfMercy\PhpPdf\Image\SvgMetadata;
use DragonOfMercy\PhpPdf\Svg\Parser;

/**
 * Public value object representing an image to be embedded in the PDF.
 * Construct via {@see self::fromFile()}, {@see self::fromBytes()}, or
 * {@see self::fromBase64()}; format is auto-detected from magic bytes.
 *
 * The document-level registry deduplicates by content hash, so the same
 * bytes loaded via separate fromBytes() / fromFile() / fromBase64() calls
 * collapse to a single Form XObject in the output.
 */
final readonly class Image
{
    private const string JPEG_MAGIC = "\xFF\xD8\xFF";
    private const string PNG_MAGIC = "\x89PNG\r\n\x1A\n";

    /**
     * @internal Public for type-narrowing in the embedder; user code should
     *           construct via {@see self::fromFile()}, {@see self::fromBytes()},
     *           or {@see self::fromBase64()}.
     */
    public function __construct(
        public int $width,
        public int $height,
        public ImageFormat $format,
        public string $bytes,
        public JpegMetadata|PngMetadata|SvgMetadata $metadata,
        public string $contentHash,
    ) {}

    public static function fromFile(string $path): self
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new PdfException("Cannot read image file: {$path}");
        }
        return self::fromBytes($data);
    }

    /**
     * Accepts either a raw base64 string or a data URI of the form
     * `data:image/png;base64,...` (the prefix is stripped automatically).
     */
    public static function fromBase64(string $data): self
    {
        if (str_starts_with($data, 'data:')) {
            $comma = strpos($data, ',');
            if ($comma === false) {
                throw new PdfException('Invalid data URI: missing comma separator');
            }
            $data = substr($data, $comma + 1);
        }

        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw new PdfException('Invalid base64-encoded image data');
        }

        return self::fromBytes($decoded);
    }

    public static function fromBytes(string $data): self
    {
        if (strlen($data) < 8) {
            throw new PdfException('Image data is too short to identify format');
        }

        $hash = hash('xxh128', $data);

        if (str_starts_with($data, self::JPEG_MAGIC)) {
            $meta = JpegMetadata::parse($data);
            return new self(
                width: $meta->width,
                height: $meta->height,
                format: ImageFormat::JPEG,
                bytes: $data,
                metadata: $meta,
                contentHash: $hash,
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
                contentHash: $hash,
            );
        }

        if (self::looksLikeSvg($data)) {
            $meta = Parser::parse($data);
            $w = (int) ceil($meta->viewBox->width);
            $h = (int) ceil($meta->viewBox->height);
            return new self(
                width: $w,
                height: $h,
                format: ImageFormat::SVG,
                bytes: $data,
                metadata: $meta,
                contentHash: $hash,
            );
        }

        throw new PdfException('Unsupported image format (expected JPEG, PNG, or SVG)');
    }

    private const string UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * Cheap, non-allocating prefix scan. Skips an optional UTF-8 BOM and ASCII
     * whitespace, then matches either '<?xml' (with a later '<svg') or '<svg'.
     * Returns true for any input whose initial useful character sequence looks
     * like SVG; the parser then performs full XML validation.
     */
    private static function looksLikeSvg(string $data): bool
    {
        $offset = 0;
        if (str_starts_with($data, self::UTF8_BOM)) {
            $offset = 3;
        }
        $offset += strspn($data, " \t\r\n", $offset);

        if (substr($data, $offset, 5) === '<?xml') {
            // Scan ahead (capped) for the first '<svg' tag start.
            $svgPos = strpos($data, '<svg', $offset);
            return $svgPos !== false && $svgPos - $offset < 4096;
        }

        return substr($data, $offset, 4) === '<svg';
    }
}
