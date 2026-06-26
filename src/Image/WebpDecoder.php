<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Decodes WebP bytes to raw 8-bit samples. PDF has no native WebP support, so a
 * WebP must be decoded to RGB(A) and re-embedded as a FlateDecode raster.
 *
 * Backend order: Imagick first (full-range 0-255 alpha, better color), then GD
 * (7-bit alpha rescaled to 8-bit opacity) as the portable fallback.
 *
 * @internal
 */
final class WebpDecoder
{
    /**
     * @return array{width:int, height:int, rgb:string, alpha:?string}
     *   rgb: packed RGB, 3 bytes/pixel, row-major top-to-bottom (length w*h*3).
     *   alpha: packed 8-bit opacity (255 = opaque), 1 byte/pixel (length w*h), or
     *          null when the source has no alpha channel / is fully opaque.
     */
    public static function decode(string $bytes): array
    {
        if (self::imagickCanDecode()) {
            return self::decodeWithImagick($bytes);
        }
        if (function_exists('imagecreatefromwebp')) {
            return self::decodeWithGd($bytes);
        }
        throw new PdfException('WebP support requires ext-imagick or ext-gd compiled with WebP support');
    }

    public static function isAvailable(): bool
    {
        return self::imagickCanDecode() || function_exists('imagecreatefromwebp');
    }

    private static function imagickCanDecode(): bool
    {
        if (!class_exists('Imagick')) {
            return false;
        }
        return in_array('WEBP', \Imagick::queryFormats('WEBP'), true);
    }

    /**
     * @return array{width:int, height:int, rgb:string, alpha:?string}
     */
    private static function decodeWithImagick(string $bytes): array
    {
        $im = new \Imagick();
        try {
            $im->readImageBlob($bytes);
            $im->setFirstIterator(); // animated WebP -> first frame
            $width = $im->getImageWidth();
            $height = $im->getImageHeight();
            $hasAlpha = $im->getImageAlphaChannel();

            $rgb = self::packChars($im->exportImagePixels(0, 0, $width, $height, 'RGB', \Imagick::PIXEL_CHAR));
            $alpha = null;
            if ($hasAlpha) {
                // ImageMagick alpha: 255 = opaque, which is exactly the PDF SMask opacity convention.
                $alpha = self::packChars($im->exportImagePixels(0, 0, $width, $height, 'A', \Imagick::PIXEL_CHAR));
                if (strspn($alpha, "\xFF") === strlen($alpha)) {
                    $alpha = null; // fully opaque
                }
            }

            return ['width' => $width, 'height' => $height, 'rgb' => $rgb, 'alpha' => $alpha];
        } catch (\ImagickException $e) {
            throw new PdfException('Malformed WebP image: ' . $e->getMessage());
        } finally {
            $im->clear();
        }
    }

    /**
     * @return array{width:int, height:int, rgb:string, alpha:?string}
     */
    private static function decodeWithGd(string $bytes): array
    {
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw new PdfException('Malformed WebP image (GD could not decode it)');
        }
        $width = imagesx($img);
        $height = imagesy($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);

        $rgb = '';
        $alpha = '';
        $hasAlpha = false;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $c = imagecolorat($img, $x, $y);
                $rgb .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF);
                // GD alpha: 0 = opaque, 127 = transparent (7-bit). Rescale to 8-bit opacity.
                $a = ($c >> 24) & 0x7F;
                if ($a !== 0) {
                    $hasAlpha = true;
                }
                $alpha .= chr(intdiv((127 - $a) * 255, 127) & 0xFF);
            }
        }
        imagedestroy($img);

        return ['width' => $width, 'height' => $height, 'rgb' => $rgb, 'alpha' => $hasAlpha ? $alpha : null];
    }

    /**
     * Packs an array of 0-255 ints into a binary string, chunked to stay under
     * the argument-count cap of pack('C*', ...) for large images.
     *
     * @param list<int> $samples
     */
    private static function packChars(array $samples): string
    {
        $out = '';
        foreach (array_chunk($samples, 8192) as $chunk) {
            $out .= pack('C*', ...$chunk);
        }
        return $out;
    }
}
