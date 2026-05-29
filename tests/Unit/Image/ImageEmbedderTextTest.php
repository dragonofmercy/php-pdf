<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Image\ImageEmbedder;
use DragonOfMercy\PhpPdf\Image\SvgMetadata;
use DragonOfMercy\PhpPdf\Svg\SvgFontResolver;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class ImageEmbedderTextTest extends TestCase
{
    public function testSvgFormReferencesFontObjects(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<text x="10" y="20" font-family="sans-serif" font-size="12">Hi</text></svg>';
        $image = Image::fromBytes($svg);

        // Mimic the Document pre-pass: register the text fonts so a short name exists.
        $registry = new FontRegistry();
        $meta = $image->metadata;
        assert($meta instanceof SvgMetadata);
        foreach ($meta->textFontSpecs() as $spec) {
            $font = SvgFontResolver::resolve($spec['family'], $spec['bold'], $spec['italic'], []);
            $registry->shortName($font);
        }
        $shortName = $registry->shortName(Font::helvetica());
        $fontRefs = [$shortName => PdfReference::to(99, 0)];

        $objects = (new ImageEmbedder())->embed($image, 5, $registry, $fontRefs);
        $formBytes = $objects[0]->toBytes();

        self::assertStringContainsString('/Font', $formBytes);
        self::assertStringContainsString('/' . $shortName . ' 99 0 R', $formBytes);
        self::assertStringContainsString('/Text', $formBytes);
    }
}
