<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Image;

/**
 * Per-Document registry of images. Allocates short PDF names ("Im1", "Im2", ...)
 * on first use and caches by realpath (for string paths) or spl_object_id
 * (for Image instances). String and instance keys live in disjoint namespaces.
 *
 * @internal
 */
final class ImageRegistry
{
    /** @var array<string, array{Image, string}> internal key => [image, shortName] */
    private array $entries = [];

    /**
     * Registers the image if not already present and returns
     * [shortName, resolvedImage]. Pure cache lookup if already registered.
     *
     * @return array{string, Image}
     */
    public function register(string|Image $image): array
    {
        $key = $this->key($image);

        if (isset($this->entries[$key])) {
            return [$this->entries[$key][1], $this->entries[$key][0]];
        }

        $resolved = $image instanceof Image
            ? $image
            : Image::fromFile(self::resolvePath($image));

        $next = 'Im' . (count($this->entries) + 1);
        $this->entries[$key] = [$resolved, $next];
        return [$next, $resolved];
    }

    public function shortName(string|Image $image): string
    {
        return $this->register($image)[0];
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * @return list<Image>
     */
    public function registeredImages(): array
    {
        return array_map(static fn (array $entry): Image => $entry[0], array_values($this->entries));
    }

    private function key(string|Image $image): string
    {
        if ($image instanceof Image) {
            return 'obj:' . spl_object_id($image);
        }
        return 'path:' . self::resolvePath($image);
    }

    private static function resolvePath(string $path): string
    {
        $real = realpath($path);
        if ($real === false) {
            throw new PdfException("Image file not found: {$path}");
        }
        return $real;
    }
}
