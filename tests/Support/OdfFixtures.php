<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Support;

use RuntimeException;
use ZipArchive;

/**
 * Builds minimal OpenDocument packages for tests.
 */
final class OdfFixtures
{
    public const TEXT = 'application/vnd.oasis.opendocument.text';

    public const SPREADSHEET = 'application/vnd.oasis.opendocument.spreadsheet';

    public const PRESENTATION = 'application/vnd.oasis.opendocument.presentation';

    private const NAMESPACES = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
        .' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
        .' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
        .' xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0"'
        .' xmlns:presentation="urn:oasis:names:tc:opendocument:xmlns:presentation:1.0"'
        .' xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"'
        .' xmlns:dc="http://purl.org/dc/elements/1.1/"'
        .' office:version="1.3"';

    private const EXTENSIONS = [
        self::TEXT => 'odt',
        self::SPREADSHEET => 'ods',
        self::PRESENTATION => 'odp',
    ];

    /**
     * @param  string  $bodyXml  Children of office:body, e.g. "<office:text>…</office:text>".
     * @param  string|null  $manifestXml  Replaces the default META-INF/manifest.xml.
     */
    public static function odf(string $mimetype, string $bodyXml, ?string $manifestXml = null): string
    {
        return self::package($mimetype, [
            'content.xml' => self::content($bodyXml),
            'META-INF/manifest.xml' => $manifestXml ?? self::manifest($mimetype),
        ]);
    }

    /**
     * Writes the mimetype entry first and uncompressed, as the OpenDocument packaging rules require.
     *
     * @param  array<string, string>  $entries  entry name => contents
     */
    public static function package(string $mimetype, array $entries): string
    {
        $path = Fixtures::path(self::EXTENSIONS[$mimetype] ?? 'zip');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create fixture package.');
        }

        $zip->addFromString('mimetype', $mimetype);
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    public static function content(string $bodyXml): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<office:document-content '.self::NAMESPACES.'>'
            .'<office:automatic-styles><text:p>style noise</text:p></office:automatic-styles>'
            .'<office:body>'.$bodyXml.'</office:body>'
            .'</office:document-content>';
    }

    public static function manifest(string $mimetype, string $extra = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
            .'<manifest:file-entry manifest:full-path="/" manifest:media-type="'.$mimetype.'"/>'
            .'<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml">'.$extra.'</manifest:file-entry>'
            .'</manifest:manifest>';
    }
}
