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

    public function testDifferentPathsGetSequentialShortNames(): void
    {
        $r = new ImageRegistry();
        self::assertSame('Im1', $r->shortName($this->tempPng()));
        self::assertSame('Im2', $r->shortName($this->tempPng()));
        self::assertSame('Im3', $r->shortName($this->tempPng()));
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

    public function testTwoIdenticalInstancesGetDistinctShortNames(): void
    {
        $r = new ImageRegistry();
        $bytes = TestImageFactory::pngRgb(4, 4);
        $a = Image::fromBytes($bytes);
        $b = Image::fromBytes($bytes);   // Different instance, same bytes.
        self::assertSame('Im1', $r->shortName($a));
        self::assertSame('Im2', $r->shortName($b));
        self::assertCount(2, $r->registeredImages());
    }

    public function testStringAndInstanceLiveInDifferentKeyspaces(): void
    {
        $r = new ImageRegistry();
        $path = $this->tempPng();
        $img = Image::fromBytes(TestImageFactory::pngRgb(4, 4));
        self::assertSame('Im1', $r->shortName($path));
        self::assertSame('Im2', $r->shortName($img));
    }

    public function testNonexistentPathThrows(): void
    {
        $r = new ImageRegistry();
        $this->expectException(PdfException::class);
        $r->shortName('/nonexistent/path/img.png');
    }
}
