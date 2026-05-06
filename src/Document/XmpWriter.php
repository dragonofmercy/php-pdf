<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Document;

/**
 * Generates an XMP packet (XML/RDF) from Metadata.
 *
 * @internal
 */
final class XmpWriter
{
    public function write(Metadata $metadata): string
    {
        $fields = $this->fields($metadata);
        $fieldsBlock = $fields === '' ? '' : "\n" . $fields;

        $rdf = '<rdf:Description rdf:about=""'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:xmp="http://ns.adobe.com/xap/1.0/"'
            . ' xmlns:pdf="http://ns.adobe.com/pdf/1.3/">'
            . $fieldsBlock
            . "\n</rdf:Description>";

        return "<?xpacket begin=\"\xEF\xBB\xBF\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n"
            . "<x:xmpmeta xmlns:x=\"adobe:ns:meta/\">\n"
            . "<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">\n"
            . $rdf . "\n"
            . "</rdf:RDF>\n"
            . "</x:xmpmeta>\n"
            . '<?xpacket end="w"?>';
    }

    private function fields(Metadata $m): string
    {
        $lines = [];
        if ($m->title !== null) {
            $lines[] = '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">'
                . self::esc($m->title) . '</rdf:li></rdf:Alt></dc:title>';
        }
        if ($m->author !== null) {
            $lines[] = '<dc:creator><rdf:Seq><rdf:li>'
                . self::esc($m->author) . '</rdf:li></rdf:Seq></dc:creator>';
        }
        if ($m->subject !== null) {
            $lines[] = '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">'
                . self::esc($m->subject) . '</rdf:li></rdf:Alt></dc:description>';
        }
        if ($m->keywords !== null) {
            $lines[] = '<pdf:Keywords>' . self::esc($m->keywords) . '</pdf:Keywords>';
        }
        if ($m->creator !== null) {
            $lines[] = '<xmp:CreatorTool>' . self::esc($m->creator) . '</xmp:CreatorTool>';
        }
        if ($m->producer !== null) {
            $lines[] = '<pdf:Producer>' . self::esc($m->producer) . '</pdf:Producer>';
        }
        if ($m->creationDate !== null) {
            $lines[] = '<xmp:CreateDate>' . $m->creationDate->format('Y-m-d\\TH:i:sP') . '</xmp:CreateDate>';
        }
        if ($m->modDate !== null) {
            $lines[] = '<xmp:ModifyDate>' . $m->modDate->format('Y-m-d\\TH:i:sP') . '</xmp:ModifyDate>';
        }
        return implode("\n", $lines);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
