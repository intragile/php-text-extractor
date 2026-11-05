<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Process;

/**
 * Resolves an external tool to an executable path.
 */
final class ToolLocator
{
    private const EXTRA_DIRECTORIES = ['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin', '/bin'];

    /**
     * Only successful lookups are cached: a missing tool is a retryable failure, and a long-lived
     * worker must notice once it has been installed.
     *
     * @var array<string, string>
     */
    private array $cache = [];

    /**
     * @param  string|null  $configured  An explicit path, or a bare command name to search for.
     */
    public function find(string $name, ?string $configured = null): ?string
    {
        $key = $name."\0".($configured ?? '');

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $path = $this->resolve($configured ?? $name);

        if ($path !== null) {
            $this->cache[$key] = $path;
        }

        return $path;
    }

    private function resolve(string $candidate): ?string
    {
        if ($candidate === '') {
            return null;
        }

        if (str_contains($candidate, '/') || str_contains($candidate, '\\')) {
            return $this->isExecutable($candidate) ? $candidate : null;
        }

        $directories = array_merge(
            explode(PATH_SEPARATOR, (string) getenv('PATH')),
            self::EXTRA_DIRECTORIES,
        );
        $names = PHP_OS_FAMILY === 'Windows' ? [$candidate.'.exe', $candidate] : [$candidate];

        foreach (array_unique(array_filter($directories)) as $directory) {
            foreach ($names as $file) {
                $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$file;

                if ($this->isExecutable($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    private function isExecutable(string $path): bool
    {
        return is_file($path) && is_executable($path);
    }
}
