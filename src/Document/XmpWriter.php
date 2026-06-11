<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Document;

use DragonOfMercy\PhpPdf\PdfA\PdfALevel;

/**
 * Generates an XMP packet (XML/RDF) from Metadata.
 *
 * @internal
 */
final class XmpWriter
{
    public function write(Metadata $metadata, ?PdfALevel $pdfa = null, bool $pdfUa = false): string
    {
        $fields = $this->fields($metadata);
        $fieldsBlock = $fields === '' ? '' : "\n" . $fields;

        $descriptions = '';
        if ($pdfa !== null) {
            $descriptions .= '<rdf:Description rdf:about=""'
                . ' xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/">'
                . '<pdfaid:part>' . $pdfa->part() . '</pdfaid:part>'
                . $this->pdfaidConformanceOrRev($pdfa)
                . "</rdf:Description>\n";
        }

        if ($pdfUa) {
            $descriptions .= '<rdf:Description rdf:about=""'
                . ' xmlns:pdfuaid="http://www.aiim.org/pdfua/ns/id/">'
                . '<pdfuaid:part>1</pdfuaid:part>'
                . "</rdf:Description>\n";
        }

        // PDF/A (ISO 19005 clause 6.6.2.3.1) only admits XMP properties that are
        // predefined or declared in an extension schema. The pdfuaid namespace is
        // not a predefined PDF/A schema, so when a document is simultaneously
        // PDF/A and PDF/UA the pdfuaid:part property must be described through a
        // PDF/A extension schema. Pure-UA (pdfa null) and pure-A (pdfUa false)
        // documents are untouched, preserving their byte-identical goldens.
        if ($pdfa !== null && $pdfUa) {
            $descriptions .= $this->pdfUaExtensionSchema();
        }

        $descriptions .= '<rdf:Description rdf:about=""'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:xmp="http://ns.adobe.com/xap/1.0/"'
            . ' xmlns:pdf="http://ns.adobe.com/pdf/1.3/">'
            . $fieldsBlock
            . "\n</rdf:Description>";

        return "<?xpacket begin=\"\xEF\xBB\xBF\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n"
            . "<x:xmpmeta xmlns:x=\"adobe:ns:meta/\">\n"
            . "<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">\n"
            . $descriptions . "\n"
            . "</rdf:RDF>\n"
            . "</x:xmpmeta>\n"
            . '<?xpacket end="w"?>';
    }

    /**
     * Parts 2-3 carry a conformance letter only; part 4 (PDF/A-4) carries a
     * revision year, and PDF/A-4f additionally carries conformance "F"
     * (ISO 19005-4:2020 clause 6.7.3).
     */
    private function pdfaidConformanceOrRev(PdfALevel $pdfa): string
    {
        $result = '';
        $rev = $pdfa->xmpRev();
        if ($rev !== null) {
            $result .= '<pdfaid:rev>' . $rev . '</pdfaid:rev>';
        }
        $conformance = $pdfa->xmpConformance();
        if ($conformance !== null) {
            $result .= '<pdfaid:conformance>' . $conformance . '</pdfaid:conformance>';
        }
        return $result;
    }

    /**
     * PDF/A extension schema description for the PDF/UA identification schema,
     * declaring the single pdfuaid:part property (Integer). Required so a
     * combined PDF/A + PDF/UA document satisfies ISO 19005 clause 6.6.2.3.1.
     */
    private function pdfUaExtensionSchema(): string
    {
        return '<rdf:Description rdf:about=""'
            . ' xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/"'
            . ' xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#"'
            . ' xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#">'
            . '<pdfaExtension:schemas><rdf:Bag><rdf:li rdf:parseType="Resource">'
            . '<pdfaSchema:schema>PDF/UA identification schema</pdfaSchema:schema>'
            . '<pdfaSchema:namespaceURI>http://www.aiim.org/pdfua/ns/id/</pdfaSchema:namespaceURI>'
            . '<pdfaSchema:prefix>pdfuaid</pdfaSchema:prefix>'
            . '<pdfaSchema:property><rdf:Seq><rdf:li rdf:parseType="Resource">'
            . '<pdfaProperty:name>part</pdfaProperty:name>'
            . '<pdfaProperty:valueType>Integer</pdfaProperty:valueType>'
            . '<pdfaProperty:category>internal</pdfaProperty:category>'
            . '<pdfaProperty:description>Indicates the PDF/UA version to which a document conforms</pdfaProperty:description>'
            . '</rdf:li></rdf:Seq></pdfaSchema:property>'
            . '</rdf:li></rdf:Bag></pdfaExtension:schemas>'
            . "</rdf:Description>\n";
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
