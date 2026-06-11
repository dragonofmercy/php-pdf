<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Signature\AppendedFieldRevisionBuilder;
use DragonOfMercy\PhpPdf\Signature\RevisionContext;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use PHPUnit\Framework\TestCase;

final class AppendedFieldRevisionBuilderReuseTest extends TestCase
{
    public function testBuildReuseSetsVOnExistingField(): void
    {
        $catalog = IndirectObject::of(1, 0, Dictionary::empty()->withEntry(Name::of('Type'), Name::of('Catalog')));
        $acro = IndirectObject::of(2, 0, Dictionary::empty()->withEntry(Name::of('Fields'), PdfArray::of(PdfReference::to(4, 0))));
        $page = IndirectObject::of(3, 0, Dictionary::empty()->withEntry(Name::of('Type'), Name::of('Page')));
        $field = IndirectObject::of(4, 0, Dictionary::empty()
            ->withEntry(Name::of('FT'), Name::of('Sig'))
            ->withEntry(Name::of('T'), PdfString::of('CounterSign')));
        $ctx = new RevisionContext($catalog, $acro, $page, 4, 'AABB');

        $built = (new AppendedFieldRevisionBuilder())->buildReuse(
            $ctx,
            static fn (int $n): IndirectObject => IndirectObject::of($n, 0, Dictionary::empty()->withEntry(Name::of('Type'), Name::of('Sig'))),
            $field,
        );

        $bytes = '';
        foreach ($built['objects'] as $o) {
            $bytes .= $o->toBytes();
        }
        self::assertStringContainsString('/V 5 0 R', $bytes);
        self::assertStringContainsString('/SigFlags 3', $bytes);
        self::assertSame(6, $built['size']);
    }
}
