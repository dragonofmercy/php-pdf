<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DOMDocument;
use DragonOfMercy\PhpPdf\Svg\Mask\MaskResolver;
use DragonOfMercy\PhpPdf\Svg\Mask\MaskUnits;
use DragonOfMercy\PhpPdf\Svg\Mask\SvgMask;
use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\StyleResolver;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use PHPUnit\Framework\TestCase;

final class StyleResolverMaskTest extends TestCase
{
    public function testMaskAttributeResolvesViaResolver(): void
    {
        $resolver = $this->buildResolverWith('m1');
        $paint = StyleResolver::resolve(
            SvgPaint::default(),
            ['mask' => 'url(#m1)'],
            [],
            '',
            SvgColor::black(),
            null,
            null,
            null,
            $resolver,
        );
        self::assertNotNull($paint->mask);
        self::assertSame('m1', $paint->mask->id);
    }

    public function testMaskNoneClearsMask(): void
    {
        $resolver = $this->buildResolverWith('m1');
        $paint = StyleResolver::resolve(
            SvgPaint::default()->withMask(new SvgMask('seed', MaskUnits::OBJECT_BOUNDING_BOX, MaskUnits::USER_SPACE_ON_USE, -0.1, -0.1, 1.2, 1.2, [])),
            ['mask' => 'none'],
            [],
            '',
            SvgColor::black(),
            null,
            null,
            null,
            $resolver,
        );
        self::assertNull($paint->mask);
    }

    public function testMaskWithoutResolverIsSilentNull(): void
    {
        $paint = StyleResolver::resolve(
            SvgPaint::default(),
            ['mask' => 'url(#whatever)'],
            [],
            '',
            SvgColor::black(),
            null,
            null,
            null,
            null,
        );
        self::assertNull($paint->mask);
    }

    public function testMaskUnknownIdIsSilentNull(): void
    {
        $resolver = $this->buildResolverWith('exists');
        $paint = StyleResolver::resolve(
            SvgPaint::default(),
            ['mask' => 'url(#missing)'],
            [],
            '',
            SvgColor::black(),
            null,
            null,
            null,
            $resolver,
        );
        self::assertNull($paint->mask);
    }

    public function testStyleAttributeWinsOverPresentationAttr(): void
    {
        $resolver = $this->buildResolverWith('m1');
        $paint = StyleResolver::resolve(
            SvgPaint::default(),
            ['mask' => 'url(#missing)'],
            [],
            'mask: url(#m1)',
            SvgColor::black(),
            null,
            null,
            null,
            $resolver,
        );
        self::assertNotNull($paint->mask);
        self::assertSame('m1', $paint->mask->id);
    }

    /**
     * Builds a MaskResolver that knows one mask with the given id and a single
     * white rect child (so resolve() succeeds).
     */
    private function buildResolverWith(string $id): MaskResolver
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
            . '<defs>'
            . '<mask id="' . htmlspecialchars($id, ENT_QUOTES) . '">'
            .   '<rect x="0" y="0" width="10" height="10" fill="white"/>'
            . '</mask>'
            . '</defs></svg>';
        $doc = new DOMDocument();
        $doc->loadXML($svg);
        $defs = [];
        foreach ($doc->getElementsByTagNameNS(Parser::SVG_NS, 'mask') as $el) {
            $defs[$el->getAttribute('id')] = $el;
        }
        return new MaskResolver($defs, new Parser());
    }
}
