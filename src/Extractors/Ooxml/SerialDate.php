<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Extractors\Ooxml;

/**
 * Turns spreadsheet date serials into ISO 8601 text: "YYYY-MM-DD", "YYYY-MM-DDTHH:MM:SS" or
 * "HH:MM:SS", the same forms OpenDocument stores in office:date-value.
 *
 * A serial counts days since the workbook's epoch, its fraction the time of day. Values are
 * rounded to whole seconds, so a fraction just below midnight carries into the next day.
 *
 * @internal
 */
final class SerialDate
{
    private const SECONDS_PER_DAY = 86400;

    /** 9999-12-31, the last day spreadsheet applications can display, in the 1900 system. */
    private const MAX_DAYS_1900 = 2958465;

    /** 9999-12-31 in the 1904 system. */
    private const MAX_DAYS_1904 = 2957003;

    /** Serial 60 is 1900-02-29, a day that never existed (Lotus 1-2-3 treated 1900 as a leap year). */
    private const PHANTOM_LEAP_DAY = 60;

    /** Days from 0000-03-01 to 1970-01-01 in the proleptic Gregorian calendar. */
    private const UNIX_EPOCH_DAYS = 719468;

    /** 1899-12-30 and 1904-01-01 as days since 1970-01-01. */
    private const EPOCH_1900 = -25569;

    private const EPOCH_1904 = -24107;

    /**
     * @param  int  $kind  one of the CellFormats kinds
     * @return string|null null when the value is not a displayable date serial for that kind
     */
    public static function format(string $value, int $kind, bool $date1904): ?string
    {
        if (! in_array($kind, [CellFormats::DATE, CellFormats::TIME, CellFormats::DATE_TIME], true)) {
            return null;
        }

        $value = trim($value);

        if (! is_numeric($value)) {
            return null;
        }

        $serial = (float) $value;
        $maxDays = $date1904 ? self::MAX_DAYS_1904 : self::MAX_DAYS_1900;

        // The comparison also rejects NAN; anything past 9999-12-31 is shown as hashes by Excel.
        if (! ($serial >= 0 && $serial < $maxDays + 1)) {
            return null;
        }

        $seconds = (int) round($serial * self::SECONDS_PER_DAY);
        $days = intdiv($seconds, self::SECONDS_PER_DAY);
        $time = self::time($seconds % self::SECONDS_PER_DAY);

        if ($kind === CellFormats::TIME) {
            return $time;
        }

        if ($days > $maxDays) {
            return null;
        }

        // In the 1900 system serial 0 is the non-date "1900-01-00"; a value below one day is a
        // time of day entered without a date, so only the time is meaningful.
        if (! $date1904 && $days === 0) {
            return $time;
        }

        if (! $date1904 && $days === self::PHANTOM_LEAP_DAY) {
            // There is no correct date to show for 1900-02-29, and 1900-02-28 or 03-01 would
            // collide with serials 59 and 61, so the number is left as stored.
            return null;
        }

        $date = self::date($days, $date1904);

        return $kind === CellFormats::DATE ? $date : $date.'T'.$time;
    }

    private static function date(int $days, bool $date1904): string
    {
        if ($date1904) {
            $unixDays = self::EPOCH_1904 + $days;
        } else {
            // Serials before the phantom leap day are one day ahead of the real calendar.
            $unixDays = self::EPOCH_1900 + $days + ($days < self::PHANTOM_LEAP_DAY ? 1 : 0);
        }

        [$year, $month, $day] = self::civilFromDays($unixDays);

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private static function time(int $seconds): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /**
     * Converts days since 1970-01-01 to a proleptic Gregorian date (Howard Hinnant's algorithm),
     * avoiding DateTime and ext-calendar for a pure integer computation.
     *
     * @return array{int, int, int} year, month, day
     */
    private static function civilFromDays(int $days): array
    {
        $days += self::UNIX_EPOCH_DAYS;
        $era = intdiv($days >= 0 ? $days : $days - 146096, 146097);
        $dayOfEra = $days - $era * 146097;
        $yearOfEra = intdiv($dayOfEra - intdiv($dayOfEra, 1460) + intdiv($dayOfEra, 36524) - intdiv($dayOfEra, 146096), 365);
        $dayOfYear = $dayOfEra - (365 * $yearOfEra + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100));
        $monthIndex = intdiv(5 * $dayOfYear + 2, 153);
        $day = $dayOfYear - intdiv(153 * $monthIndex + 2, 5) + 1;
        $month = $monthIndex < 10 ? $monthIndex + 3 : $monthIndex - 9;
        $year = $yearOfEra + $era * 400 + ($month <= 2 ? 1 : 0);

        return [$year, $month, $day];
    }
}
