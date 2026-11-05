<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Process;

/**
 * Runs an external tool without a shell, with a wall-clock timeout, an optional per-process
 * address-space cap and bounded output.
 *
 * The cap is applied with "ulimit -v" because it limits the child alone: a container memory limit
 * covers the whole container, so one oversized input would otherwise take the PHP worker down with
 * it. Where the limit is not enforced (macOS, Windows) the tool simply runs uncapped.
 *
 * Each run gets a fresh, empty working directory. Tesseract, for one, treats an input it cannot
 * decode as a list of file names, so a crafted upload could otherwise make it read files that
 * happen to sit in the worker's current directory.
 */
final class ProcessRunner
{
    private const STDERR_LIMIT = 65536;

    private const READ_CHUNK = 65536;

    private const POLL_MICROSECONDS = 100000;

    private const KILL_GRACE_SECONDS = 2.0;

    /**
     * @param  list<string>  $command  The executable followed by its arguments.
     * @param  int  $memoryMb  Address-space cap in MiB; 0 disables it.
     */
    public function run(array $command, int $timeoutSeconds, int $memoryMb, int $maxStdoutBytes): ProcessResult
    {
        $directory = $this->createWorkingDirectory();

        if ($directory === null) {
            return new ProcessResult(null, '', 'A private working directory could not be created.', false, false);
        }

        try {
            return $this->runIn($directory, $command, $timeoutSeconds, $memoryMb, $maxStdoutBytes);
        } finally {
            $this->removeWorkingDirectory($directory);
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function runIn(string $directory, array $command, int $timeoutSeconds, int $memoryMb, int $maxStdoutBytes): ProcessResult
    {
        $pipes = [];
        $process = @proc_open(
            $this->wrap($command, $memoryMb),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $directory,
        );

        if (! is_resource($process)) {
            return new ProcessResult(null, '', 'The process could not be started.', false, false);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + $timeoutSeconds;
        $stdout = '';
        $stderr = '';
        $truncated = false;
        $timedOut = false;
        $exitCode = null;
        $exited = false;
        $open = [1 => $pipes[1], 2 => $pipes[2]];

        while (true) {
            if ($open !== []) {
                $read = array_values($open);
                $write = null;
                $except = null;
                @stream_select($read, $write, $except, 0, self::POLL_MICROSECONDS);

                foreach ($open as $index => $pipe) {
                    $chunk = fread($pipe, self::READ_CHUNK);

                    if (is_string($chunk) && $chunk !== '') {
                        if ($index === 1) {
                            $room = $maxStdoutBytes - strlen($stdout);

                            if (strlen($chunk) > $room) {
                                $truncated = true;
                                $chunk = $room > 0 ? substr($chunk, 0, $room) : '';
                            }

                            $stdout .= $chunk;
                        } else {
                            $stderr .= substr($chunk, 0, max(0, self::STDERR_LIMIT - strlen($stderr)));
                        }
                    }

                    if (feof($pipe)) {
                        fclose($pipe);
                        unset($open[$index]);
                    }
                }
            } else {
                usleep(10000);
            }

            if (! $exited) {
                $status = proc_get_status($process);

                if (! $status['running']) {
                    $exited = true;
                    $exitCode = $this->exitCode($status);
                }
            }

            if ($exited && $open === []) {
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = ! $exited;
                break;
            }
        }

        foreach ($open as $pipe) {
            fclose($pipe);
        }

        if ($timedOut) {
            $this->kill($process);
            $exitCode = null;
        }

        proc_close($process);

        return new ProcessResult($exitCode, $stdout, $stderr, $timedOut, $truncated);
    }

    private function createWorkingDirectory(): ?string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'txx_run_'.bin2hex(random_bytes(8));

            if (@mkdir($directory, 0700)) {
                return $directory;
            }
        }

        return null;
    }

    private function removeWorkingDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $directory.DIRECTORY_SEPARATOR.$entry;

                if (is_file($path) || is_link($path)) {
                    @unlink($path);
                }
            }
        }

        @rmdir($directory);
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function wrap(array $command, int $memoryMb): array
    {
        if ($memoryMb <= 0 || PHP_OS_FAMILY === 'Windows' || ! is_executable('/bin/sh')) {
            return $command;
        }

        $quoted = implode(' ', array_map([self::class, 'quote'], $command));

        // ulimit's own complaint on systems that do not support -v must not reach stderr, where it
        // would turn a legitimately empty result into a failure.
        return ['/bin/sh', '-c', 'ulimit -v '.($memoryMb * 1024).' 2>/dev/null; exec '.$quoted];
    }

    /**
     * Locale-independent POSIX single-quoting; escapeshellarg() can drop non-ASCII bytes.
     */
    private static function quote(string $argument): string
    {
        return "'".str_replace("'", "'\\''", $argument)."'";
    }

    /**
     * @param  array{running: bool, signaled: bool, termsig: int, exitcode: int}  $status
     */
    private function exitCode(array $status): int
    {
        if ($status['signaled']) {
            return 128 + $status['termsig'];
        }

        return $status['exitcode'];
    }

    /**
     * @param  resource  $process
     */
    private function kill($process): void
    {
        proc_terminate($process, 15);
        $grace = microtime(true) + self::KILL_GRACE_SECONDS;

        while (microtime(true) < $grace) {
            if (! proc_get_status($process)['running']) {
                return;
            }

            usleep(20000);
        }

        proc_terminate($process, 9);
    }
}
