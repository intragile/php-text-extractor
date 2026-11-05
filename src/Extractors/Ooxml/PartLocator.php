<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Extractors\Ooxml;

use IntrAgile\TextExtractor\Archive\SafeZip;

/**
 * Finds OOXML parts by relationship type rather than by conventional file name.
 *
 * Types are matched on their last path segment so that both the transitional
 * (schemas.openxmlformats.org) and the strict (purl.oclc.org) relationship URIs are recognised.
 */
final class PartLocator
{
    /**
     * Resolves the package's main part through _rels/.rels, falling back to the conventional path.
     */
    public static function mainPart(SafeZip $zip, string $conventionalPath): ?string
    {
        foreach (self::byType($zip, '', 'officeDocument') as $part) {
            if ($zip->has($part)) {
                return $part;
            }
        }

        return $zip->has($conventionalPath) ? $conventionalPath : null;
    }

    /**
     * @param  string  $sourcePart  The part owning the relationships; '' for the package itself.
     * @return list<string> existing target parts in document order
     */
    public static function byType(SafeZip $zip, string $sourcePart, string $type): array
    {
        $parts = [];

        foreach (Relationships::entries($zip, self::relationshipsPath($sourcePart)) as $entry) {
            if (str_ends_with($entry['type'], '/'.$type) && $zip->has($entry['target'])) {
                $parts[] = $entry['target'];
            }
        }

        return $parts;
    }

    /**
     * "word/document.xml" keeps its relationships in "word/_rels/document.xml.rels".
     */
    public static function relationshipsPath(string $part): string
    {
        if ($part === '') {
            return '_rels/.rels';
        }

        $slash = strrpos($part, '/');

        return $slash === false
            ? '_rels/'.$part.'.rels'
            : substr($part, 0, $slash).'/_rels/'.substr($part, $slash + 1).'.rels';
    }
}
