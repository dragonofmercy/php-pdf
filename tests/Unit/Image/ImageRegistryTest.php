<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Image\ImageRegistry;
use DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory;
use PHPUnit\Framework\TestCase;

final class ImageRegistryTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
    }

    private function tempPng(int $width = 4, int $height = 4): string
    {
        $path = sys_get_temp_dir() . '/phppdf-reg-' . uniqid('', true) . '.png';
        file_put_contents($path, TestImageFactory::pngRgb($width, $height));
        $this->tempFiles[] = $path;
        return $path;
    }

    public function testInitiallyEmpty(): void
    {
        $r = new ImageRegistry();
        self::assertTrue($r->isEmpty());
        self::assertSame([], $r->registeredImages());
    }

    public function testFirstStringPathGetsIm1(): void
    {
        $r = new ImageRegistry();
        $name = $r->shortName($this->tempPng());
        self::assertSame('Im1', $name);
        self::assertFalse($r->isEmpty());
        self::assertCount(1, $r->registeredImages());
    }

    public function testSamePathReturnsSameShortName(): void
    {
        $r = new ImageRegistry();
        $path = $this->tempPng();
        $a = $r->shortName($path);
        $b = $r->shortName($path);
        self::assertSame($a, $b);
        self::assertSame('Im1', $a);
        self::assertCount(1, $r->registeredImages());
    }

    public function testDifferentContentGetsSequentialShortNames(): void
    {
        $r = new ImageRegistry();
        self::assertSame('Im1', $r->shortName($this->tempPng(4, 4)));
        self::assertSame('Im2', $r->shortName($this->tempPng(4, 5)));
        self::assertSame('Im3', $r->shortName($this->tempPng(4, 6)));
        self::assertCount(3, $r->registeredImages());
    }

    public function testSameInstanceReturnsSameShortName(): void
    {
        $r = new ImageRegistry();
        $img = Image::fromBytes(TestImageFactory::pngRgb(4, 4));
        self::assertSame('Im1', $r->shortName($img));
        self::assertSame('Im1', $r->shortName($img));
        self::assertCount(1, $r->registeredImages());
    }

    public function testIdenticalContentAcrossInstancesDedups(): void
    {
        $r = new ImageRegistry();
        $bytes = TestImageFactory::pngRgb(4, 4);
        $a = Image::fromBytes($bytes);
        $b = Image::fromBytes($bytes);
        self::assertSame('Im1', $r->shortName($a));
        self::assertSame('Im1', $r->shortName($b));
        self::assertCount(1, $r->registeredImages());
    }

    public function testIdenticalContentAcrossPathsDedups(): void
    {
        $r = new ImageRegistry();
        self::assertSame('Im1', $r->shortName($this->tempPng(4, 4)));
        self::assertSame('Im1', $r->shortName($this->tempPng(4, 4)));
        self::assertCount(1, $r->registeredImages());
    }

    public function testPathAndInstanceWithSameContentDedup(): void
    {
        $r = new ImageRegistry();
        $bytes = TestImageFactory::pngRgb(4, 4);
        $path = sys_get_temp_dir() . '/phppdf-reg-mixed-' . uniqid('', true) . '.png';
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;
        $img = Image::fromBytes($bytes);

        self::assertSame('Im1', $r->shortName($path));
        self::assertSame('Im1', $r->shortName($img));
        self::assertCount(1, $r->registeredImages());
    }

    public function testNonexistentPathThrows(): void
    {
        $r = new ImageRegistry();
        $this->expectException(PdfException::class);
        $r->shortName('/nonexistent/path/img.png');
    }

    public function testRegisterReturnsShortNameAndImage(): void
    {
        $r = new ImageRegistry();
        $img = Image::fromBytes(TestImageFactory::pngRgb(8, 4));
        [$name, $resolved] = $r->register($img);
        self::assertSame('Im1', $name);
        self::assertSame($img, $resolved);
        // Second call returns the cached entry.
        [$name2, $resolved2] = $r->register($img);
        self::assertSame('Im1', $name2);
        self::assertSame($img, $resolved2);
        self::assertCount(1, $r->registeredImages());
    }
}
