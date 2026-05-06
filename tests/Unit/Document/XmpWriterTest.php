<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Document;

use DateTimeImmutable;
use DragonOfMercy\PhpPdf\Document\Metadata;
use DragonOfMercy\PhpPdf\Document\XmpWriter;
use PHPUnit\Framework\TestCase;

final class XmpWriterTest extends TestCase
{
    public function testPacketEnvelopeIsWellFormed(): void
    {
        $xmp = (new XmpWriter())->write(new Metadata());
        self::assertStringStartsWith("<?xpacket begin=\"\xEF\xBB\xBF\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>", $xmp);
        self::assertStringContainsString('<x:xmpmeta xmlns:x="adobe:ns:meta/">', $xmp);
        self::assertStringContainsString('<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">', $xmp);
        self::assertStringContainsString('</rdf:RDF>', $xmp);
        self::assertStringContainsString('</x:xmpmeta>', $xmp);
        self::assertStringEndsWith('<?xpacket end="w"?>', $xmp);
    }

    public function testTitleIsMappedToDcTitleAlt(): void
    {
        $m = (new Metadata())->title('Hello');
        $xmp = (new XmpWriter())->write($m);
        self::assertStringContainsString('<dc:title><rdf:Alt><rdf:li xml:lang="x-default">Hello</rdf:li></rdf:Alt></dc:title>', $xmp);
    }

    public function testAuthorIsMappedToDcCreatorSeq(): void
    {
        $m = (new Metadata())->author('Jane');
        $xmp = (new XmpWriter())->write($m);
        self::assertStringContainsString('<dc:creator><rdf:Seq><rdf:li>Jane</rdf:li></rdf:Seq></dc:creator>', $xmp);
    }

    public function testProducerIsMappedToPdfProducer(): void
    {
        $m = (new Metadata())->producer('phppdf 0.1-phase1a');
        $xmp = (new XmpWriter())->write($m);
        self::assertStringContainsString('<pdf:Producer>phppdf 0.1-phase1a</pdf:Producer>', $xmp);
    }

    public function testCreationDateIsIso8601(): void
    {
        $m = (new Metadata())->creationDate(new DateTimeImmutable('2026-01-01T12:00:00+00:00'));
        $xmp = (new XmpWriter())->write($m);
        self::assertStringContainsString('<xmp:CreateDate>2026-01-01T12:00:00+00:00</xmp:CreateDate>', $xmp);
    }

    public function testXmlSpecialCharsAreEscaped(): void
    {
        $m = (new Metadata())->title('Hello <World> & "friends"');
        $xmp = (new XmpWriter())->write($m);
        self::assertStringContainsString('Hello &lt;World&gt; &amp; &quot;friends&quot;', $xmp);
        self::assertStringNotContainsString('Hello <World>', $xmp);
    }

    public function testEmptyMetadataStillHasValidPacket(): void
    {
        $xmp = (new XmpWriter())->write(new Metadata());
        self::assertStringContainsString('<rdf:Description rdf:about=""', $xmp);
    }
}
