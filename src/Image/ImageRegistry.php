<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Image;

/**
 * Per-Document registry of images. Allocates short PDF names ("Im1", "Im2", ...)
 * on first use and caches by content hash. Identical bytes loaded through
 * different paths or distinct Image instances collapse to a single XObject.
 * Path-based registration also keeps a path -> hash alias to avoid re-reading
 * the same file on repeat calls.
 *
 * @internal
 */
final class ImageRegistry
{
    /** @var array<string, array{Image, string}> contentHash => [image, shortName] */
    private array $entries = [];

    /** @var array<string, string> realpath => contentHash */
    private array $pathToHash = [];

    /**
     * Registers the image if not already present and returns
     * [shortName, resolvedImage]. Pure cache lookup if already registered.
     *
     * @return array{string, Image}
     */
    public function register(string|Image $image): array
    {
        if (is_string($image)) {
            $path = self::resolvePath($image);
            if (isset($this->pathToHash[$path])) {
                $entry = $this->entries[$this->pathToHash[$path]];
                return [$entry[1], $entry[0]];
            }
            $image = Image::fromFile($path);
            $this->pathToHash[$path] = $image->contentHash;
        }

        $key = $image->contentHash;
        if (isset($this->entries[$key])) {
            return [$this->entries[$key][1], $this->entries[$key][0]];
        }
        return $this->store($key, $image);
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

    /**
     * @return array{string, Image}
     */
    private function store(string $key, Image $image): array
    {
        $next = 'Im' . (count($this->entries) + 1);
        $this->entries[$key] = [$image, $next];
        return [$next, $image];
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
