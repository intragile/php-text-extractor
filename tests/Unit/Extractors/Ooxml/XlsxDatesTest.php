<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Extractors\Ooxml;

use IntrAgile\TextExtractor\Extractors\Ooxml\XlsxExtractor;
use IntrAgile\TextExtractor\Extractors\OpenDocumentExtractor;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;
use IntrAgile\TextExtractor\Status;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\OdfFixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\Tests\Support\XlsxFixtures;
use IntrAgile\TextExtractor\TextExtractor;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Number cells whose style carries a date or time format are written in ISO 8601 form.
 */
final class XlsxDatesTest extends TestCase
{
    /** Serial of 2026-11-05 in the 1900 date system. */
    private const SERIAL_1900 = 46331;

    /** Serial of 2026-11-05 in the 1904 date system. */
    private const SERIAL_1904 = 44869;

    protected function setUp(): void
    {
        if (! extension_loaded('zip') || ! extension_loaded('xmlreader')) {
            $this->markTestSkipped('ext-zip and ext-xmlreader are required.');
        }
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function builtInFormats(): array
    {
        $date = '2026-11-05';
        $time = '10:30:00';
        $both = '2026-11-05T10:30:00';
        $serial = '46331.4375';

        return [
            'general' => [0, $serial, $serial],
            'decimal' => [2, $serial, $serial],
            'text' => [49, $serial, $serial],
            'm/d/yyyy' => [14, $serial, $date],
            'd-mmm-yy' => [15, $serial, $date],
            'd-mmm' => [16, $serial, $date],
            'mmm-yy' => [17, $serial, $date],
            'h:mm AM/PM' => [18, $serial, $time],
            'h:mm:ss AM/PM' => [19, $serial, $time],
            'h:mm' => [20, $serial, $time],
            'h:mm:ss' => [21, $serial, $time],
            'm/d/yyyy h:mm' => [22, $serial, $both],
            'mm:ss' => [45, $serial, $time],
            '[h]:mm:ss is a duration' => [46, $serial, $serial],
            'mm:ss.0' => [47, $serial, $time],
            'east asian date 27' => [27, $serial, $date],
            'east asian date 31' => [31, $serial, $date],
            'east asian time 32' => [32, $serial, $time],
            'east asian time 35' => [35, $serial, $time],
            'east asian date 36' => [36, $serial, $date],
            'east asian date 50' => [50, $serial, $date],
            'east asian date 54' => [54, $serial, $date],
            'east asian time 55' => [55, $serial, $time],
            'east asian time 56' => [56, $serial, $time],
            'east asian date 57' => [57, $serial, $date],
            'east asian date 58' => [58, $serial, $date],
        ];
    }

    #[DataProvider('builtInFormats')]
    public function test_built_in_number_formats(int $numFmtId, string $value, string $expected): void
    {
        $this->assertSame($expected, $this->cell($value, XlsxFixtures::styles([0, $numFmtId]), 's="1"'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function customFormats(): array
    {
        $date = '2026-11-05';
        $time = '10:30:00';
        $both = '2026-11-05T10:30:00';
        $serial = '46331.4375';

        return [
            'iso date' => ['yyyy-mm-dd', $date],
            'escaped quote before a time token' => ['\\"h', $time],
            'escaped quote inside a number format' => ['0\\" "x d"', $serial],
            'upper case' => ['YYYY-MM-DD', $date],
            'hungarian with locale' => ['[$-40E]yyyy\. mmmm d\.;@', $date],
            'month alone is a date' => ['m', $date],
            'month name' => ['mmmm', $date],
            'day only' => ['dd', $date],
            'with a colour' => ['[Blue]yyyy-mm-dd', $date],
            'date and time' => ['dd/mm/yyyy hh:mm', $both],
            'date and seconds' => ['yyyy-mm-dd"T"hh:mm:ss', $both],
            'time' => ['hh:mm:ss', $time],
            'time with am/pm' => ['h:mm AM/PM', $time],
            'minutes and seconds' => ['mm:ss', $time],
            'elapsed hours' => ['[h]:mm', $serial],
            'elapsed minutes' => ['[mm]:ss', $serial],
            'elapsed seconds' => ['[ss]', $serial],
            'elapsed hours upper case' => ['[H]:MM:SS', $serial],
            'quoted letters' => ['0.00" days"', $serial],
            'escaped letters' => ['0\d\a\y', $serial],
            'padding and fill characters' => ['_d0*s', $serial],
            'colour name with date letters' => ['[Red]0.00', $serial],
            'locale tag' => ['[$-409]#,##0', $serial],
            'condition' => ['[<=9999999]###-####', $serial],
            'currency literal' => ['#,##0 "Ft"', $serial],
            'scientific' => ['0.00E+00', $serial],
            'general' => ['General', $serial],
            'text' => ['@', $serial],
        ];
    }

    #[DataProvider('customFormats')]
    public function test_custom_number_formats(string $formatCode, string $expected): void
    {
        $styles = XlsxFixtures::styles([0, 164], [164 => $formatCode]);

        $this->assertSame($expected, $this->cell('46331.4375', $styles, 's="1"'));
    }

    public function test_custom_format_overrides_a_built_in_id(): void
    {
        $styles = XlsxFixtures::styles([14], [14 => '0.00']);

        $this->assertSame('46331', $this->cell('46331', $styles, 's="0"'));
    }

    public function test_1904_date_system(): void
    {
        $styles = XlsxFixtures::styles([14, 22]);
        $rows = '<row><c s="0"><v>'.self::SERIAL_1904.'</v></c><c s="1"><v>0.5</v></c></row>';

        foreach (['date1904="1"', 'date1904="true"'] as $attribute) {
            $result = $this->extract(XlsxFixtures::xlsx(['S' => $rows], [], $styles, $attribute));

            $this->assertSame("## S\n2026-11-05\t1904-01-01T12:00:00", $result->text, $attribute);
        }

        $result = $this->extract(XlsxFixtures::xlsx(['S' => $rows], [], $styles, 'date1904="0"'));

        $this->assertSame("## S\n2022-11-04\t12:00:00", $result->text);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function earlySerials(): array
    {
        return [
            'first day' => ['1', '1900-01-01'],
            'before the phantom leap day' => ['59', '1900-02-28'],
            'the phantom leap day itself' => ['60', '60'],
            'the phantom leap day with a time' => ['60.5', '60.5'],
            'after the phantom leap day' => ['61', '1900-03-01'],
            'today' => ['46331', '2026-11-05'],
            'last representable day' => ['2958465', '9999-12-31'],
            'past the last representable day' => ['2958466', '2958466'],
        ];
    }

    #[DataProvider('earlySerials')]
    public function test_1900_date_system_and_its_phantom_leap_day(string $value, string $expected): void
    {
        $this->assertSame($expected, $this->cell($value, XlsxFixtures::styles([14]), 's="0"'));
    }

    public function test_rounding_to_whole_seconds_may_carry_into_the_next_day(): void
    {
        $styles = XlsxFixtures::styles([22, 14, 21]);
        $rows = '<row>'
            .'<c s="0"><v>46330.999999999</v></c>'
            .'<c s="0"><v>46331.43750001</v></c>'
            .'<c s="1"><v>46330.999999999</v></c>'
            .'<c s="2"><v>0.999999999</v></c>'
            .'<c s="0"><v>59.999999999</v></c>'
            .'</row>';

        $result = $this->extract(XlsxFixtures::xlsx(['S' => $rows], [], $styles));

        // The last serial rounds onto the phantom leap day, which has no date to show.
        $this->assertSame("## S\n2026-11-05T00:00:00\t2026-11-05T10:30:00\t2026-11-05\t00:00:00\t59.999999999", $result->text);
    }

    public function test_time_only_formats_and_serials_below_one(): void
    {
        $styles = XlsxFixtures::styles([14, 22, 21]);
        $rows = '<row>'
            .'<c s="0"><v>0.4375</v></c>'
            .'<c s="1"><v>0.4375</v></c>'
            .'<c s="2"><v>46331.4375</v></c>'
            .'<c s="2"><v>0</v></c>'
            .'</row>';

        $result = $this->extract(XlsxFixtures::xlsx(['S' => $rows], [], $styles));

        // A date-only format has no date to show for a serial below one day, so it stays a number.
        $this->assertSame("## S\n0.4375\t10:30:00\t10:30:00\t00:00:00", $result->text);
    }

    public function test_only_plain_numbers_are_converted(): void
    {
        $styles = XlsxFixtures::styles([14]);
        $rows = '<row>'
            .'<c s="0" t="n"><v>46331</v></c>'
            .'<c s="0" t="s"><v>0</v></c>'
            .'<c s="0" t="str"><v>46331</v></c>'
            .'<c s="0" t="inlineStr"><is><t>46331</t></is></c>'
            .'<c s="0" t="e"><v>#N/A</v></c>'
            .'<c s="0" t="b"><v>1</v></c>'
            .'<c s="0" t="d"><v>2026-11-05T00:00:00</v></c>'
            .'</row>'
            .'<row>'
            .'<c s="0"><v>-1</v></c>'
            .'<c s="0"><v>abc</v></c>'
            .'<c s="0"><v>NAN</v></c>'
            .'<c s="0"><v>1e300</v></c>'
            .'<c s="0"><v> 46331 </v></c>'
            .'<c s="0"><v>4.6331E4</v></c>'
            .'</row>';

        $result = $this->extract(XlsxFixtures::xlsx(['S' => $rows], ['shared'], $styles));

        $this->assertSame(
            "## S\n2026-11-05\tshared\t46331\t46331\t#N/A\tTRUE\t2026-11-05T00:00:00\n-1\tabc\tNAN\t1e300\t2026-11-05\t2026-11-05",
            $result->text,
        );
    }

    public function test_unknown_or_invalid_style_references_leave_the_number(): void
    {
        $styles = XlsxFixtures::styles([0, 14]);
        $rows = '<row><c><v>46331</v></c><c s="0"><v>46331</v></c><c s="7"><v>46331</v></c><c s="x"><v>46331</v></c><c s="-1"><v>46331</v></c></row>';

        $result = $this->extract(XlsxFixtures::xlsx(['S' => $rows], [], $styles));

        $this->assertSame("## S\n46331\t46331\t46331\t46331\t46331", $result->text);
    }

    public function test_cell_style_xfs_are_not_taken_for_cell_xfs(): void
    {
        // The fixture's cellStyleXfs hold a date format at index 1; cellXfs index 1 is General.
        $this->assertSame('46331', $this->cell('46331', XlsxFixtures::styles([0, 0]), 's="1"'));
    }

    public function test_styles_part_at_the_conventional_path_is_used_without_a_relationship(): void
    {
        $entries = XlsxFixtures::entries(['S' => '<row><c s="0"><v>46331</v></c></row>']);
        $entries['xl/styles.xml'] = XlsxFixtures::styles([14]);

        $result = $this->extract(Fixtures::zip($entries, 'xlsx'));

        $this->assertSame("## S\n2026-11-05", $result->text);
    }

    public function test_missing_styles_leave_numbers_unchanged(): void
    {
        $result = $this->extract(XlsxFixtures::xlsx(['S' => '<row><c s="0"><v>46331</v></c></row>']));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame("## S\n46331", $result->text);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function brokenStyles(): array
    {
        return [
            'not well-formed' => ['<?xml version="1.0"?><styleSheet><cellXfs><xf numFmtId="14"></cellXfs>'],
            'empty' => [''],
            'not xml' => ['garbage'],
            'with a doctype' => ['<?xml version="1.0"?><!DOCTYPE s [<!ENTITY e "x">]><styleSheet/>'],
            'non-numeric ids' => ['<?xml version="1.0"?><styleSheet><numFmts><numFmt numFmtId="x" formatCode="yyyy"/></numFmts><cellXfs><xf numFmtId="abc"/><xf/></cellXfs></styleSheet>'],
        ];
    }

    #[DataProvider('brokenStyles')]
    public function test_broken_styles_leave_numbers_unchanged(string $styles): void
    {
        $result = $this->extract(XlsxFixtures::xlsx(['S' => '<row><c s="0"><v>46331</v></c><c s="1"><v>7</v></c></row>'], [], $styles));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame("## S\n46331\t7", $result->text);
    }

    public function test_a_broken_styles_part_after_good_formats_keeps_none_of_them(): void
    {
        $styles = substr(XlsxFixtures::styles([14, 14]), 0, -20);

        $result = $this->extract(XlsxFixtures::xlsx(['S' => '<row><c s="0"><v>46331</v></c></row>'], [], $styles));

        $this->assertSame("## S\n46331", $result->text);
    }

    public function test_millions_of_cell_formats_hit_the_archive_limit(): void
    {
        $styles = '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs>'
            .str_repeat('<xf/>', 1_100_000).'</cellXfs></styleSheet>';
        $path = XlsxFixtures::xlsx(['S' => '<row><c s="0"><v>1</v></c></row>'], [], $styles);
        unset($styles);

        $growth = self::peakGrowth(function () use ($path, &$result): void {
            $result = $this->extract($path, new Options(maxOutputBytes: 1000));
        });

        $this->assertInstanceOf(Result::class, $result);
        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::ArchiveLimitExceeded, $result->reason);
        $this->assertLessThan(24 * 1024 * 1024, $growth);
    }

    public function test_too_many_custom_number_formats_hit_the_archive_limit(): void
    {
        $formats = '';

        for ($id = 164; $id < 164 + 70_000; $id++) {
            $formats .= '<numFmt numFmtId="'.$id.'" formatCode="yyyy"/>';
        }

        $styles = '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts>'
            .$formats.'</numFmts><cellXfs><xf numFmtId="164"/></cellXfs></styleSheet>';

        $result = $this->extract(XlsxFixtures::xlsx(['S' => '<row><c s="0"><v>1</v></c></row>'], [], $styles));

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::ArchiveLimitExceeded, $result->reason);
    }

    public function test_dates_match_the_open_document_output(): void
    {
        $ods = OdfFixtures::odf(OdfFixtures::SPREADSHEET, '<office:spreadsheet><table:table table:name="S">'
            .'<table:table-row>'
            .'<table:table-cell office:value-type="date" office:date-value="2026-11-05"><text:p>05/11/2026</text:p></table:table-cell>'
            .'<table:table-cell office:value-type="date" office:date-value="2026-11-05T10:30:00"><text:p>05/11/2026 10:30</text:p></table:table-cell>'
            .'<table:table-cell office:value-type="time" office:time-value="PT10H30M00S"><text:p>10:30 AM</text:p></table:table-cell>'
            .'</table:table-row>'
            .'</table:table></office:spreadsheet>');
        $odsResult = (new TextExtractor(extractors: [new OpenDocumentExtractor]))->extract($ods);

        $xlsx = XlsxFixtures::xlsx(
            ['S' => '<row><c s="0"><v>'.self::SERIAL_1900.'</v></c><c s="1"><v>'.self::SERIAL_1900.'.4375</v></c><c s="2"><v>0.4375</v></c></row>'],
            [],
            XlsxFixtures::styles([14, 22, 20]),
        );

        $this->assertSame("## S\n2026-11-05\t2026-11-05T10:30:00\t10:30:00", $odsResult->text);
        $this->assertSame($odsResult->text, $this->extract($xlsx)->text);
    }

    public function test_conditional_format_number_formats_do_not_override_cell_formats(): void
    {
        $styles = str_replace('</styleSheet>', '<dxfs count="1"><dxf><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/></dxf></dxfs></styleSheet>', XlsxFixtures::styles([0, 164], [164 => '0.00']));

        $this->assertSame('45000', $this->cell('45000', $styles, 's="1"'));
    }

    public function test_a_cell_without_a_style_uses_the_first_cell_format(): void
    {
        $this->assertSame('2023-03-15', $this->cell('45000', XlsxFixtures::styles([14]), 't="n"'));
    }

    private function cell(string $value, string $styles, string $attributes): string
    {
        $result = $this->extract(XlsxFixtures::xlsx(['S' => '<row><c '.$attributes.'><v>'.$value.'</v></c></row>'], [], $styles));

        $this->assertSame(Status::Extracted, $result->status);

        return substr($result->text, strlen("## S\n"));
    }

    private function extract(string $path, ?Options $options = null): Result
    {
        return (new TextExtractor($options, extractors: [new XlsxExtractor]))->extract($path);
    }
}
