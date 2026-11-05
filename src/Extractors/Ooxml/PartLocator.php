<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Extractors\Ooxml;

use IntrAgile\TextExtractor\Archive\SafeZip;
use XMLReader;

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
        $relsPath = self::relationshipsPath($sourcePart);

        if (! $zip->has($relsPath)) {
            return [];
        }

        $ids = [];
        $reader = $zip->xmlReader($relsPath);

        try {
            while ($reader->read()) {
                if ($reader->nodeType() !== XMLReader::ELEMENT || $reader->localName() !== 'Relationship') {
                    continue;
                }

                $id = $reader->attribute('Id');
                $relationshipType = (string) $reader->attribute('Type');

                if ($id !== null && str_ends_with($relationshipType, '/'.$type)) {
                    $ids[] = $id;
                }
            }
        } finally {
            $reader->close();
        }

        if ($ids === []) {
            return [];
        }

        $targets = Relationships::parse($zip, $relsPath);
        $parts = [];

        foreach ($ids as $id) {
            if (isset($targets[$id]) && $zip->has($targets[$id])) {
                $parts[] = $targets[$id];
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
