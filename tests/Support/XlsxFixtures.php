<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Support;

/**
 * Builds minimal but realistic SpreadsheetML packages.
 */
final class XlsxFixtures
{
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * @param  array<string, string>  $sheets  sheet name => sheetData body (the <row> elements)
     * @param  list<string>  $sharedStrings  plain text, or the raw inner XML of <si> when it starts with "<"
     */
    public static function xlsx(array $sheets, array $sharedStrings = []): string
    {
        return Fixtures::zip(self::entries($sheets, $sharedStrings), 'xlsx');
    }

    /**
     * Worksheet parts are numbered in reverse workbook order, so a reader that relies on part
     * names instead of the workbook's sheet list produces the wrong order.
     *
     * @param  array<string, string>  $sheets  sheet name => sheetData body (the <row> elements)
     * @param  list<string>  $sharedStrings  plain text, or the raw inner XML of <si> when it starts with "<"
     * @return array<string, string> entry name => contents
     */
    public static function entries(array $sheets, array $sharedStrings = []): array
    {
        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
        $count = count($sheets);
        $overrides = '';
        $sheetList = '';
        $workbookRels = '';
        $parts = [];
        $position = 0;

        foreach ($sheets as $name => $rows) {
            $file = 'sheet'.($count - $position).'.xml';
            $rId = 'rId'.($position + 1);
            $parts['xl/worksheets/'.$file] = $head
                .'<worksheet xmlns="'.self::MAIN.'" xmlns:r="'.self::REL.'">'
                .'<dimension ref="A1"/><sheetViews><sheetView workbookViewId="0"/></sheetViews>'
                .'<sheetData>'.$rows.'</sheetData>'
                .'<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
                .'</worksheet>';
            $overrides .= '<Override PartName="/xl/worksheets/'.$file.'" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheetList .= '<sheet name="'.self::escape((string) $name).'" sheetId="'.($position + 1).'" r:id="'.$rId.'"/>';
            $workbookRels .= '<Relationship Id="'.$rId.'" Type="'.self::REL.'/worksheet" Target="worksheets/'.$file.'"/>';
            $position++;
        }

        $workbookRels .= '<Relationship Id="rId'.($count + 1).'" Type="'.self::REL.'/sharedStrings" Target="sharedStrings.xml"/>';

        $items = '';

        foreach ($sharedStrings as $string) {
            $items .= str_starts_with($string, '<')
                ? '<si>'.$string.'</si>'
                : '<si><t xml:space="preserve">'.self::escape($string).'</t></si>';
        }

        return [
            '[Content_Types].xml' => $head
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .$overrides
                .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
                .'</Types>',
            '_rels/.rels' => $head
                .'<Relationships xmlns="'.self::PKG_REL.'">'
                .'<Relationship Id="rId1" Type="'.self::REL.'/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => $head
                .'<workbook xmlns="'.self::MAIN.'" xmlns:r="'.self::REL.'">'
                .'<bookViews><workbookView/></bookViews>'
                .'<sheets>'.$sheetList.'</sheets>'
                .'</workbook>',
            'xl/_rels/workbook.xml.rels' => $head
                .'<Relationships xmlns="'.self::PKG_REL.'">'.$workbookRels.'</Relationships>',
            'xl/sharedStrings.xml' => $head
                .'<sst xmlns="'.self::MAIN.'" count="'.count($sharedStrings).'" uniqueCount="'.count($sharedStrings).'">'
                .$items
                .'</sst>',
        ] + $parts;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
