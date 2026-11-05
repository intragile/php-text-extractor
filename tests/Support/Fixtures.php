<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Support;

use RuntimeException;
use ZipArchive;

/**
 * Builds throw-away input files for tests and removes them afterwards.
 */
final class Fixtures
{
    /** @var list<string> */
    private static array $paths = [];

    public static function file(string $contents, string $extension = 'bin'): string
    {
        $path = self::path($extension);
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param  array<string, string>  $entries  entry name => contents
     */
    public static function zip(array $entries, string $extension = 'zip'): string
    {
        $path = self::path($extension);
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create fixture zip.');
        }

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    /**
     * Path of a committed fixture under tests/fixtures.
     */
    public static function committed(string $relative): string
    {
        return dirname(__DIR__).'/fixtures/'.$relative;
    }

    public static function path(string $extension = 'bin'): string
    {
        $base = tempnam(sys_get_temp_dir(), 'txt_fx_');

        if ($base === false) {
            throw new RuntimeException('Cannot create a temporary fixture file.');
        }

        unlink($base);
        $path = $base.'.'.$extension;
        self::$paths[] = $path;

        return $path;
    }

    public static function cleanup(): void
    {
        foreach (self::$paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        self::$paths = [];
    }
}
