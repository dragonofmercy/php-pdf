<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Parsed PNG header + IDAT stream(s). For color types that carry alpha
 * (GRAY_ALPHA, RGB_ALPHA, or PALETTE with a tRNS chunk) the alpha channel
 * is separated out into a parallel zlib stream so the embedder can emit
 * an /SMask XObject.
 *
 * Field semantics:
 *  - $idatBytes: zlib-compressed PNG-filtered bytes for the main image.
 *    For separated color types, this is null and $colorBytes is used instead.
 *  - $palette: raw PLTE bytes (3 bytes per entry) for PALETTE images, null otherwise.
 *  - $colorBytes: zlib-compressed color-only stream when alpha was separated;
 *    null when the original idat is used as-is.
 *  - $alphaBytes: zlib-compressed alpha-only stream when alpha is present;
 *    null for fully opaque images.
 *
 * @internal
 */
final readonly class PngMetadata
{
    public const string SIGNATURE = "\x89PNG\r\n\x1A\n";

    public function __construct(
        public int $width,
        public int $height,
        public int $bitDepth,
        public PngColorType $colorType,
        public string $idatBytes,
        public ?string $palette,
        public ?string $alphaBytes,
        public ?string $colorBytes,
    ) {}

    public static function parse(string $data): self
    {
        $len = strlen($data);
        if ($len < 8 || substr($data, 0, 8) !== self::SIGNATURE) {
            throw new PdfException('Invalid PNG signature (corrupt file or wrong format)');
        }

        $i = 8;
        $width = $height = $bitDepth = $colorTypeValue = null;
        $palette = null;
        $trns = null;
        $idat = '';

        while ($i + 8 <= $len) {
            $unpacked = unpack('N', substr($data, $i, 4));
            if ($unpacked === false || !is_int($unpacked[1])) {
                throw new PdfException('PNG chunk header is malformed');
            }
            $chunkLen = $unpacked[1];
            $type = substr($data, $i + 4, 4);
            $payloadStart = $i + 8;
            if ($payloadStart + $chunkLen + 4 > $len) {
                throw new PdfException('PNG chunk extends past end of stream');
            }
            $payload = substr($data, $payloadStart, $chunkLen);
            // We do not validate CRCs (some encoders are sloppy; PDF embedding does not need them).

            if ($type === 'IHDR') {
                if ($chunkLen !== 13) {
                    throw new PdfException('PNG IHDR chunk is malformed');
                }
                $wUnpacked = unpack('N', substr($payload, 0, 4));
                $hUnpacked = unpack('N', substr($payload, 4, 4));
                if ($wUnpacked === false || $hUnpacked === false || !is_int($wUnpacked[1]) || !is_int($hUnpacked[1])) {
                    throw new PdfException('PNG IHDR width/height is malformed');
                }
                $width = $wUnpacked[1];
                $height = $hUnpacked[1];
                $bitDepth = ord($payload[8]);
                $colorTypeValue = ord($payload[9]);
                $compMethod = ord($payload[10]);
                $filterMethod = ord($payload[11]);
                $interlaceMethod = ord($payload[12]);
                if ($compMethod !== 0) {
                    throw new PdfException('PNG uses unsupported compression method');
                }
                if ($filterMethod !== 0) {
                    throw new PdfException('PNG uses unsupported filter method');
                }
                if ($interlaceMethod === 1) {
                    throw new PdfException('Interlaced PNG (Adam7) is not supported');
                }
                if ($bitDepth === 16) {
                    throw new PdfException('PNG 16-bit per component is not supported');
                }
            } elseif ($type === 'PLTE') {
                $palette = $payload;
            } elseif ($type === 'tRNS') {
                $trns = $payload;
            } elseif ($type === 'IDAT') {
                $idat .= $payload;
            } elseif ($type === 'IEND') {
                break;
            }

            $i = $payloadStart + $chunkLen + 4;
        }

        if ($width === null || $height === null || $bitDepth === null || $colorTypeValue === null) {
            throw new PdfException('PNG missing IHDR chunk (corrupt file)');
        }

        $colorType = PngColorType::tryFrom($colorTypeValue);
        if ($colorType === null) {
            throw new PdfException("PNG IHDR has unsupported color type: {$colorTypeValue}");
        }

        if ($colorType === PngColorType::PALETTE && $palette === null) {
            throw new PdfException('PNG indexed color requires PLTE chunk');
        }

        if ($idat === '') {
            throw new PdfException('PNG has no IDAT chunks (empty image)');
        }

        $alphaBytes = null;
        $colorBytes = null;

        if ($colorType === PngColorType::GRAY_ALPHA || $colorType === PngColorType::RGB_ALPHA) {
            [$colorBytes, $alphaBytes] = self::separateAlphaChannel(
                idat: $idat,
                width: $width,
                height: $height,
                bpp: $colorType === PngColorType::RGB_ALPHA ? 4 : 2,
                colorChannels: $colorType === PngColorType::RGB_ALPHA ? 3 : 1,
            );
        } elseif ($colorType === PngColorType::PALETTE && $trns !== null) {
            $alphaBytes = self::generateAlphaFromTrns(
                idat: $idat,
                width: $width,
                height: $height,
                trns: $trns,
            );
        }

        return new self(
            width: $width,
            height: $height,
            bitDepth: $bitDepth,
            colorType: $colorType,
            idatBytes: $idat,
            palette: $palette,
            alphaBytes: $alphaBytes,
            colorBytes: $colorBytes,
        );
    }

    /**
     * Decompresses idat, de-filters, splits color and alpha bytes into two separate
     * filter-prefixed scanline streams, and re-zlib-compresses each.
     *
     * @return array{string, string} [colorBytes, alphaBytes] both gzcompressed.
     */
    private static function separateAlphaChannel(
        string $idat,
        int $width,
        int $height,
        int $bpp,
        int $colorChannels,
    ): array {
        $decompressed = @gzuncompress($idat);
        if ($decompressed === false) {
            throw new PdfException('PNG IDAT zlib decompression failed');
        }
        $raw = PngFilters::unfilter($decompressed, $width, $height, $bpp);

        $colorOut = '';
        $alphaOut = '';
        $rawOffset = 0;

        for ($y = 0; $y < $height; $y++) {
            $colorRow = '';
            $alphaRow = '';
            for ($x = 0; $x < $width; $x++) {
                for ($c = 0; $c < $colorChannels; $c++) {
                    $colorRow .= $raw[$rawOffset + $c];
                }
                $alphaRow .= $raw[$rawOffset + $colorChannels];
                $rawOffset += $bpp;
            }
            $colorOut .= "\x00" . $colorRow;     // filter type None
            $alphaOut .= "\x00" . $alphaRow;
        }

        $colorCompressed = gzcompress($colorOut, 6);
        $alphaCompressed = gzcompress($alphaOut, 6);
        if ($colorCompressed === false || $alphaCompressed === false) {
            throw new PdfException('PNG channel recompression failed');
        }

        return [$colorCompressed, $alphaCompressed];
    }

    /**
     * For PALETTE + tRNS: derives a per-pixel alpha stream by looking up
     * tRNS[paletteIndex] for every pixel. tRNS may be shorter than the
     * palette; missing entries default to 0xFF (fully opaque).
     */
    private static function generateAlphaFromTrns(
        string $idat,
        int $width,
        int $height,
        string $trns,
    ): string {
        $decompressed = @gzuncompress($idat);
        if ($decompressed === false) {
            throw new PdfException('PNG IDAT zlib decompression failed');
        }
        $raw = PngFilters::unfilter($decompressed, $width, $height, bpp: 1);

        $trnsLen = strlen($trns);
        $alphaOut = '';
        $offset = 0;
        for ($y = 0; $y < $height; $y++) {
            $row = '';
            for ($x = 0; $x < $width; $x++) {
                $index = ord($raw[$offset]);
                $row .= $index < $trnsLen ? $trns[$index] : "\xFF";
                $offset++;
            }
            $alphaOut .= "\x00" . $row;
        }

        $compressed = gzcompress($alphaOut, 6);
        if ($compressed === false) {
            throw new PdfException('PNG alpha channel compression failed');
        }
        return $compressed;
    }
}
