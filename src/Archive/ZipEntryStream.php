<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Archive;

use ZipArchive;

/**
 * Stream wrapper that exposes one zip entry to XMLReader while counting the bytes it yields.
 *
 * XMLReader can only stream from a URI, and the built-in zip:// wrapper offers no way to stop a
 * decompression that outgrows its declared size; this wrapper ends the stream as soon as any
 * attached budget is exhausted.
 *
 * @internal
 */
final class ZipEntryStream
{
    public const SCHEME = 'txx-zip';

    /** @var resource|null */
    public $context;

    /** @var array<int, array{zip: ZipArchive, name: string, budgets: list<ByteBudget>}> */
    private static array $entries = [];

    private static int $sequence = 0;

    /** @var resource|null */
    private $inner;

    /** @var list<ByteBudget> */
    private array $budgets = [];

    private bool $eof = false;

    private int $token = 0;

    /**
     * @param  list<ByteBudget>  $budgets
     */
    public static function register(ZipArchive $zip, string $name, array $budgets): string
    {
        if (! in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }

        $token = ++self::$sequence;
        self::$entries[$token] = ['zip' => $zip, 'name' => $name, 'budgets' => $budgets];

        return self::SCHEME.'://'.$token;
    }

    public static function release(string $uri): void
    {
        unset(self::$entries[(int) substr($uri, strlen(self::SCHEME) + 3)]);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->token = (int) substr($path, strlen(self::SCHEME) + 3);
        $entry = self::$entries[$this->token] ?? null;

        if ($entry === null || ! str_contains($mode, 'r')) {
            return false;
        }

        $inner = $entry['zip']->getStream($entry['name']);

        if (! is_resource($inner)) {
            return false;
        }

        $this->inner = $inner;
        $this->budgets = $entry['budgets'];

        return true;
    }

    public function stream_read(int $count): string
    {
        if ($this->eof || $this->inner === null) {
            return '';
        }

        $data = fread($this->inner, max(1, $count));

        if (! is_string($data) || $data === '') {
            $this->eof = feof($this->inner) || $data === false;

            return '';
        }

        foreach ($this->budgets as $budget) {
            if (! $budget->consume(strlen($data))) {
                $this->eof = true;

                return '';
            }
        }

        return $data;
    }

    public function stream_eof(): bool
    {
        return $this->eof || $this->inner === null || feof($this->inner);
    }

    public function stream_close(): void
    {
        if (is_resource($this->inner)) {
            fclose($this->inner);
        }

        $this->inner = null;
        unset(self::$entries[$this->token]);
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        return isset(self::$entries[(int) substr($path, strlen(self::SCHEME) + 3)]) ? [] : false;
    }
}
