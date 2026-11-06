<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Archive;

use IntrAgile\TextExtractor\Exception\CorruptInput;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Support\Deadline;
use IntrAgile\TextExtractor\Xml\SafeXmlReader;
use LogicException;
use ZipArchive;

/**
 * Read-only access to a zip container (OOXML, OpenDocument) under the configured safety limits.
 */
final class SafeZip
{
    /** @var array<string, int> entry name => index */
    private array $entries = [];

    /** @var list<string> */
    private array $uris = [];

    private ByteBudget $total;

    private function __construct(
        private readonly ZipArchive $zip,
        private readonly Options $options,
        private readonly ?Deadline $deadline,
    ) {
        $this->total = new ByteBudget($options->maxArchiveTotalBytes);
    }

    /**
     * @param  Deadline|null  $deadline  Applied to every XML reader the archive hands out.
     *
     * @throws CorruptInput when the file is not a readable zip archive
     * @throws ArchiveLimitExceeded when the archive holds more entries than allowed
     */
    public static function open(string $path, Options $options, ?Deadline $deadline = null): self
    {
        if (! extension_loaded('zip')) {
            throw new LogicException('ext-zip is required to read zip containers.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new CorruptInput('The file is not a readable zip archive.');
        }

        if ($zip->numFiles > $options->maxArchiveEntries) {
            $count = $zip->numFiles;
            $zip->close();

            throw new ArchiveLimitExceeded(sprintf('The archive holds %d entries; the limit is %d.', $count, $options->maxArchiveEntries));
        }

        $archive = new self($zip, $options, $deadline);

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if (is_string($name) && self::isSafeName($name)) {
                $archive->entries[$name] = $index;
            }
        }

        return $archive;
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    /**
     * Reads a small entry completely.
     *
     * @throws ArchiveLimitExceeded when the entry yields more than $maxBytes or a limit is hit
     * @throws CorruptInput when the entry does not exist or cannot be read
     */
    public function read(string $name, int $maxBytes): string
    {
        $entry = $this->entryBudget($name, $maxBytes);
        $stream = $this->zip->getStream($name);

        if (! is_resource($stream)) {
            throw new CorruptInput(sprintf('The archive entry "%s" cannot be read.', $name));
        }

        $contents = '';

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);

                if (! is_string($chunk) || $chunk === '') {
                    break;
                }

                if (! $entry->consume(strlen($chunk)) || ! $this->total->consume(strlen($chunk))) {
                    throw new ArchiveLimitExceeded(sprintf('The archive entry "%s" exceeds the size limit.', $name));
                }

                $contents .= $chunk;
            }
        } finally {
            fclose($stream);
        }

        return $contents;
    }

    /**
     * Streams an XML entry; the reader throws ArchiveLimitExceeded once a byte limit is reached.
     */
    public function xmlReader(string $name): SafeXmlReader
    {
        $entry = $this->entryBudget($name, $this->options->maxArchiveEntryBytes);
        $total = $this->total;
        $uri = ZipEntryStream::register($this->zip, $name, [$entry, $total]);
        $this->uris[] = $uri;

        return SafeXmlReader::fromUri(
            $uri,
            static fn (): bool => $entry->isExceeded() || $total->isExceeded(),
            $this->deadline,
            static fn (): string => ZipEntryStream::head($uri),
        );
    }

    public function close(): void
    {
        foreach ($this->uris as $uri) {
            ZipEntryStream::release($uri);
        }

        $this->uris = [];
        @$this->zip->close();
    }

    public function __destruct()
    {
        foreach ($this->uris as $uri) {
            ZipEntryStream::release($uri);
        }
    }

    private function entryBudget(string $name, int $maxBytes): ByteBudget
    {
        if (! self::isSafeName($name)) {
            throw new ArchiveLimitExceeded(sprintf('The archive entry name "%s" is not allowed.', $name));
        }

        $index = $this->entries[$name] ?? null;

        if ($index === null) {
            throw new CorruptInput(sprintf('The archive entry "%s" does not exist.', $name));
        }

        $stat = $this->zip->statIndex($index);
        $limit = min($maxBytes, $this->options->maxArchiveEntryBytes);

        if (is_array($stat) && $stat['size'] > $limit) {
            throw new ArchiveLimitExceeded(sprintf('The archive entry "%s" declares %d bytes; the limit is %d.', $name, $stat['size'], $limit));
        }

        return new ByteBudget($limit);
    }

    private static function isSafeName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || $name[0] === '/' || $name[0] === '\\' || preg_match('/^[A-Za-z]:/', $name) === 1) {
            return false;
        }

        foreach (preg_split('#[/\\\\]#', $name) ?: [] as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }
}
