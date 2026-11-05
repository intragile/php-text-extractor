<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Support;

/**
 * Builds minimal but structurally realistic OOXML packages (DOCX, PPTX) for tests.
 */
final class OoxmlFixtures
{
    public const W_NAMESPACES = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
        .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        .' xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"'
        .' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
        .' xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"'
        .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        .' xmlns:v="urn:schemas-microsoft-com:vml"'
        .' xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml"'
        .' mc:Ignorable="w14"';

    public const P_NAMESPACES = 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"'
        .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        .' xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"'
        .' xmlns:p14="http://schemas.microsoft.com/office/powerpoint/2010/main"';

    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

    private const PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * @param  string  $bodyXml  Contents of w:body.
     * @param  array<string, string>  $extraParts  Additional or replacing entries; footnotes.xml and
     *                                             endnotes.xml are wired into the document relationships.
     */
    public static function docx(string $bodyXml, array $extraParts = []): string
    {
        $relationships = '';
        $overrides = '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>';

        foreach (['footnotes', 'endnotes'] as $index => $kind) {
            if (isset($extraParts['word/'.$kind.'.xml'])) {
                $relationships .= sprintf('<Relationship Id="rId%d" Type="%s%s" Target="%s.xml"/>', $index + 10, self::REL, $kind, $kind);
                $overrides .= sprintf('<Override PartName="/word/%s.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.%s+xml"/>', $kind, $kind);
            }
        }

        $entries = [
            '[Content_Types].xml' => self::contentTypes($overrides),
            '_rels/.rels' => self::rels('<Relationship Id="rId1" Type="'.self::REL.'officeDocument" Target="word/document.xml"/>'),
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<w:document '.self::W_NAMESPACES.'><w:body>'.$bodyXml
                .'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/></w:sectPr></w:body></w:document>',
            'word/_rels/document.xml.rels' => self::rels(
                '<Relationship Id="rId1" Type="'.self::REL.'styles" Target="styles.xml"/>'.$relationships,
            ),
            'word/styles.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:styles '.self::W_NAMESPACES.'/>',
        ];

        return Fixtures::zip(array_merge($entries, $extraParts), 'docx');
    }

    /**
     * Wraps w:footnote / w:endnote elements in a notes part, including the separator notes Word
     * always writes.
     */
    public static function notes(string $kind, string $notesXml): string
    {
        $separators = sprintf(
            '<w:%1$s w:type="separator" w:id="-1"><w:p><w:r><w:separator/></w:r></w:p></w:%1$s>'
            .'<w:%1$s w:type="continuationSeparator" w:id="0"><w:p><w:r><w:t>CONTINUATION</w:t></w:r></w:p></w:%1$s>',
            $kind,
        );

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .sprintf('<w:%1$ss %2$s>%3$s%4$s</w:%1$ss>', $kind, self::W_NAMESPACES, $separators, $notesXml);
    }

    public static function paragraph(string ...$runs): string
    {
        $xml = '<w:p><w:pPr><w:tabs><w:tab w:val="left" w:pos="720"/></w:tabs></w:pPr>';

        foreach ($runs as $run) {
            $xml .= '<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">'.htmlspecialchars($run, ENT_XML1).'</w:t></w:r>';
        }

        return $xml.'</w:p>';
    }

    /**
     * @param  list<string>  $slideXmls  Contents of each slide's p:spTree, stored as slide1.xml, slide2.xml, ...
     * @param  list<int>|null  $order  Slide numbers in presentation order; a number without a slide
     *                                 part produces a relationship to a missing part.
     */
    public static function pptx(array $slideXmls, ?array $order = null): string
    {
        $count = count($slideXmls);
        $order ??= $count === 0 ? [] : range(1, $count);
        $numbers = array_values(array_unique(array_merge($count === 0 ? [] : range(1, $count), $order)));
        $overrides = '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>';
        $relationships = '<Relationship Id="rId1" Type="'.self::REL.'slideMaster" Target="slideMasters/slideMaster1.xml"/>';
        $entries = [];

        foreach ($numbers as $number) {
            $relationships .= sprintf('<Relationship Id="rId%d" Type="%sslide" Target="slides/slide%d.xml"/>', $number + 100, self::REL, $number);
        }

        foreach ($slideXmls as $index => $spTree) {
            $number = $index + 1;
            $overrides .= sprintf('<Override PartName="/ppt/slides/slide%d.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>', $number);
            $entries[sprintf('ppt/slides/slide%d.xml', $number)] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<p:sld '.self::P_NAMESPACES.'><p:cSld><p:spTree>'
                .'<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
                .$spTree.'</p:spTree></p:cSld></p:sld>';
            $entries[sprintf('ppt/slides/_rels/slide%d.xml.rels', $number)] = self::rels(
                '<Relationship Id="rId1" Type="'.self::REL.'slideLayout" Target="../slideLayouts/slideLayout1.xml"/>',
            );
        }

        $slideIds = '';

        foreach ($order as $position => $number) {
            $slideIds .= sprintf('<p:sldId id="%d" r:id="rId%d"/>', 256 + $position, $number + 100);
        }

        $entries = [
            '[Content_Types].xml' => self::contentTypes($overrides),
            '_rels/.rels' => self::rels('<Relationship Id="rId1" Type="'.self::REL.'officeDocument" Target="ppt/presentation.xml"/>'),
            'ppt/presentation.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<p:presentation '.self::P_NAMESPACES.'>'
                .'<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
                .'<p:sldIdLst>'.$slideIds.'</p:sldIdLst>'
                .'<p:sldSz cx="12192000" cy="6858000"/></p:presentation>',
            'ppt/_rels/presentation.xml.rels' => self::rels($relationships),
        ] + $entries;

        return Fixtures::zip($entries, 'pptx');
    }

    public static function textShape(string ...$paragraphs): string
    {
        $xml = '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Text"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr/>'
            .'<p:txBody><a:bodyPr/><a:lstStyle/>';

        foreach ($paragraphs as $paragraph) {
            $xml .= '<a:p><a:r><a:rPr lang="en-US"/><a:t>'.htmlspecialchars($paragraph, ENT_XML1).'</a:t></a:r></a:p>';
        }

        return $xml.'</p:txBody></p:sp>';
    }

    private static function contentTypes(string $overrides): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .$overrides.'</Types>';
    }

    private static function rels(string $relationships): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="'.self::PKG_REL.'">'.$relationships.'</Relationships>';
    }
}
