<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Svg\Parser;
use PHPUnit\Framework\TestCase;

final class SvgMetadataFilterWalkTest extends TestCase
{
    public function testFontInsideFilteredElementIsRegistered(): void
    {
        $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
          <filter id="f"><feGaussianBlur stdDeviation="2"/></filter>
          <g filter="url(#f)"><text x="10" y="50" font-family="serif">Hi</text></g>
        </svg>
        SVG;
        $meta = Parser::parse($svg);
        $specs = $meta->textFontSpecs();
        self::assertNotEmpty($specs);
        self::assertSame('serif', $specs[0]['family']);
    }
}
