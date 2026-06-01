<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Svg\Parser;
use PHPUnit\Framework\TestCase;

final class SvgMetadataTextPathTest extends TestCase
{
    public function testTextPathFontIsReportedInTextFontSpecs(): void
    {
        $svg = <<<XML
        <svg xmlns="http://www.w3.org/2000/svg" width="300" height="120" viewBox="0 0 300 120">
          <path id="c" d="M0,50 L300,50"/>
          <text font-family="serif"><textPath href="#c">Hi</textPath></text>
        </svg>
        XML;
        $meta = Parser::parse($svg);
        $specs = $meta->textFontSpecs();
        $families = array_map(static fn (array $s): string => $s['family'], $specs);
        self::assertContains('serif', $families);
    }
}
