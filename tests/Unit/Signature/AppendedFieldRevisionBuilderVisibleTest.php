<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Signature\AppendedFieldRevisionBuilder;
use DragonOfMercy\PhpPdf\Signature\RevisionContext;
use DragonOfMercy\PhpPdf\Signature\SignatureAppearance;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class AppendedFieldRevisionBuilderVisibleTest extends TestCase
{
    public function testBuildVisibleEmitsRectApAndFont(): void
    {
        $catalog = IndirectObject::of(1, 0, Dictionary::empty()->withEntry(Name::of('Type'), Name::of('Catalog')));
        $acro = IndirectObject::of(2, 0, Dictionary::empty()->withEntry(Name::of('Fields'), PdfArray::of()));
        $page = IndirectObject::of(3, 0, Dictionary::empty()->withEntry(Name::of('Type'), Name::of('Page')));
        $ctx = new RevisionContext($catalog, $acro, $page, 5, 'AABB');
        $ap = new SignatureAppearance(0, 10.0, 10.0, 100.0, 40.0, 'Signed');

        $built = (new AppendedFieldRevisionBuilder())->buildVisible(
            $ctx,
            static fn (int $n): IndirectObject => IndirectObject::of($n, 0, Dictionary::empty()->withEntry(Name::of('Type'), Name::of('Sig'))),
            'VisSig',
            $page,
            [10.0, 752.0, 110.0, 792.0],
            $ap,
        );

        $bytes = '';
        foreach ($built['objects'] as $o) {
            $bytes .= $o->toBytes();
        }
        self::assertStringContainsString('/FT /Sig', $bytes);
        self::assertStringContainsString('/Rect [10 752 110 792]', $bytes);
        self::assertStringContainsString('/AP', $bytes);
        self::assertStringContainsString('/BaseFont /Helvetica', $bytes);
        self::assertStringContainsString('/SigFlags 3', $bytes);
        self::assertDoesNotMatchRegularExpression('~/Rect \[0 0 0 0\]~', $bytes);
    }
}
