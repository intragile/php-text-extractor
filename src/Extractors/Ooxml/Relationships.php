<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Extractors\Ooxml;

use IntrAgile\TextExtractor\Archive\SafeZip;
use XMLReader;

/**
 * Reads an OOXML relationships part (e.g. word/_rels/document.xml.rels).
 */
final class Relationships
{
    /**
     * @return array<string, string> relationship id => target part path from the package root
     */
    public static function parse(SafeZip $zip, string $relsPath): array
    {
        if (! $zip->has($relsPath)) {
            return [];
        }

        $base = self::sourceDirectory($relsPath);
        $reader = $zip->xmlReader($relsPath);
        $targets = [];

        while ($reader->read()) {
            if ($reader->nodeType() !== XMLReader::ELEMENT || $reader->localName() !== 'Relationship') {
                continue;
            }

            $id = $reader->attribute('Id');
            $target = $reader->attribute('Target');

            if ($id === null || $target === null || strcasecmp((string) $reader->attribute('TargetMode'), 'External') === 0) {
                continue;
            }

            $resolved = self::resolve($base, rawurldecode($target));

            if ($resolved !== null) {
                $targets[$id] = $resolved;
            }
        }

        $reader->close();

        return $targets;
    }

    /**
     * "ppt/_rels/presentation.xml.rels" describes "ppt/presentation.xml", so targets resolve from "ppt/".
     */
    private static function sourceDirectory(string $relsPath): string
    {
        $directory = dirname(dirname($relsPath));

        return $directory === '.' ? '' : $directory.'/';
    }

    private static function resolve(string $base, string $target): ?string
    {
        $path = str_starts_with($target, '/') ? substr($target, 1) : $base.$target;
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }
}
