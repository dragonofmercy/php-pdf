<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Image\ImageEmbedder;
use DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory;
use PHPUnit\Framework\TestCase;

final class SvgImageEmbedTest extends TestCase
{
    private function pngUri(int $w = 4, int $h = 2): string
    {
        return 'data:image/png;base64,' . base64_encode(TestImageFactory::pngRgb($w, $h));
    }

    private function pngAlphaUri(int $w = 4, int $h = 2): string
    {
        return 'data:image/png;base64,' . base64_encode(TestImageFactory::pngRgbAlpha($w, $h));
    }

    private function svgWith(string $body): Image
    {
        return Image::fromBytes('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">' . $body . '</svg>');
    }

    public function testObjectCountNoImage(): void
    {
        self::assertSame(1, ImageEmbedder::objectCount($this->svgWith('<rect width="10" height="10" fill="red"/>')));
    }

    public function testObjectCountOnePng(): void
    {
        self::assertSame(2, ImageEmbedder::objectCount($this->svgWith('<image width="40" height="40" href="' . $this->pngUri() . '"/>')));
    }

    public function testObjectCountPngAlphaCountsSmask(): void
    {
        self::assertSame(3, ImageEmbedder::objectCount($this->svgWith('<image width="40" height="40" href="' . $this->pngAlphaUri() . '"/>')));
    }

    public function testObjectCountDedup(): void
    {
        $uri = $this->pngUri();
        $svg = $this->svgWith('<image x="0" width="40" height="40" href="' . $uri . '"/><image x="50" width="40" height="40" href="' . $uri . '"/>');
        self::assertSame(2, ImageEmbedder::objectCount($svg));
    }

    public function testEmbedWiresXObjectResource(): void
    {
        $svg = $this->svgWith('<image width="40" height="40" href="' . $this->pngUri() . '"/>');
        $objs = (new ImageEmbedder())->embed($svg, 5);
        self::assertCount(2, $objs);
        $body = '';
        foreach ($objs as $o) {
            $body .= $o->toBytes();
        }
        self::assertStringContainsString('/XObject', $body);
        self::assertStringContainsString('/Im0', $body);
        self::assertStringContainsString('6 0 R', $body);
    }

    public function testEmbedChildNumbersSkipBySmask(): void
    {
        $svg = $this->svgWith(
            '<image x="0" width="40" height="40" href="' . $this->pngAlphaUri() . '"/>'
            . '<image x="50" width="40" height="40" href="' . $this->pngUri() . '"/>',
        );
        self::assertSame(4, ImageEmbedder::objectCount($svg));
        $objs = (new ImageEmbedder())->embed($svg, 10);
        $numbers = array_map(static fn ($o) => $o->objectNumber, $objs);
        self::assertSame([10, 11, 12, 13], $numbers);
    }

    public function testEmbedNoXObjectWhenNoImages(): void
    {
        $svg = $this->svgWith('<rect width="10" height="10" fill="red"/>');
        $objs = (new ImageEmbedder())->embed($svg, 5);
        self::assertCount(1, $objs);
        $body = '';
        foreach ($objs as $o) {
            $body .= $o->toBytes();
        }
        self::assertStringNotContainsString('/XObject <<', $body);
    }
}
