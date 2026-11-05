<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Process;

use IntrAgile\TextExtractor\Process\ProcessRunner;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Tests\Support\TestCase;

final class ProcessRunnerTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function php(string $code): array
    {
        return [PHP_BINARY, '-n', '-d', 'memory_limit=-1', '-r', $code];
    }

    public function test_captures_stdout_and_exit_code(): void
    {
        $result = (new ProcessRunner)->run($this->php('echo "hi"; exit(0);'), 10, 0, 1024);

        $this->assertSame(0, $result->exitCode);
        $this->assertSame('hi', $result->stdout);
        $this->assertFalse($result->timedOut);
        $this->assertNull($result->outcome());
    }

    public function test_non_zero_exit_is_a_failure(): void
    {
        $result = (new ProcessRunner)->run($this->php('fwrite(STDERR, "boom"); exit(3);'), 10, 0, 1024);

        $this->assertSame(3, $result->exitCode);
        $this->assertSame('boom', $result->stderr);
        $this->assertSame(Reason::ToolFailed, $result->outcome());
    }

    public function test_times_out_and_kills(): void
    {
        $started = microtime(true);
        $result = (new ProcessRunner)->run($this->php('sleep(10);'), 1, 0, 1024);

        $this->assertTrue($result->timedOut);
        $this->assertSame(Reason::ToolTimeout, $result->outcome());
        $this->assertLessThan(4.0, microtime(true) - $started);
    }

    public function test_process_ignoring_sigterm_is_killed(): void
    {
        if (! function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl is required to ignore SIGTERM in the child.');
        }

        $started = microtime(true);
        $result = (new ProcessRunner)->run([PHP_BINARY, '-r', 'pcntl_signal(SIGTERM, SIG_IGN); sleep(20);'], 1, 0, 1024);

        $this->assertTrue($result->timedOut);
        $this->assertLessThan(6.0, microtime(true) - $started);
    }

    public function test_memory_cap_kills_the_child_not_the_parent(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('The address-space limit is only enforced on Linux.');
        }

        $result = (new ProcessRunner)->run($this->php('$s = str_repeat("x", 400 * 1024 * 1024); echo strlen($s);'), 30, 128, 1024);

        $this->assertNotSame(0, $result->exitCode);
        $this->assertSame(Reason::ToolFailed, $result->outcome());
    }

    public function test_memory_cap_does_not_disturb_a_normal_run(): void
    {
        $result = (new ProcessRunner)->run($this->php('echo "fine";'), 10, 512, 1024);

        $this->assertSame(0, $result->exitCode);
        $this->assertSame('fine', $result->stdout);
        $this->assertSame('', $result->stderr);
    }

    public function test_empty_stdout_with_stderr_is_a_failure(): void
    {
        $result = (new ProcessRunner)->run($this->php('fwrite(STDERR, "err"); exit(0);'), 10, 0, 1024);

        $this->assertSame(0, $result->exitCode);
        $this->assertSame(Reason::ToolFailed, $result->outcome());
    }

    public function test_empty_stdout_without_stderr_is_success(): void
    {
        $result = (new ProcessRunner)->run($this->php('exit(0);'), 10, 0, 1024);

        $this->assertNull($result->outcome());
    }

    public function test_stdout_is_capped_without_deadlock(): void
    {
        $result = (new ProcessRunner)->run($this->php('for ($i = 0; $i < 20; $i++) { echo str_repeat("y", 1048576); } fwrite(STDERR, str_repeat("e", 200000));'), 30, 0, 1048576);

        $this->assertSame(0, $result->exitCode);
        $this->assertTrue($result->stdoutTruncated);
        $this->assertSame(1048576, strlen($result->stdout));
        $this->assertSame(65536, strlen($result->stderr));
    }

    public function test_arguments_are_not_shell_interpreted(): void
    {
        $marker = sys_get_temp_dir().'/txt_injection_'.bin2hex(random_bytes(4));
        $payload = "'; touch {$marker}; echo '\$(touch {$marker})";

        $result = (new ProcessRunner)->run($this->php('echo $argv[1];'), 10, 64, 4096);
        $withArgument = (new ProcessRunner)->run([...$this->php('echo $argv[1];'), $payload], 10, 64, 4096);

        $this->assertSame(0, $result->exitCode);
        $this->assertSame($payload, $withArgument->stdout);
        $this->assertFileDoesNotExist($marker);
    }

    public function test_tools_run_in_an_empty_private_directory_that_is_removed_afterwards(): void
    {
        $result = (new ProcessRunner)->run($this->php('echo getcwd(), "|", count(scandir(".")), "|"; file_put_contents("left-behind.txt", "x");'), 10, 0, 4096);
        [$directory, $entries] = explode('|', $result->stdout);

        $this->assertNotSame(getcwd(), $directory);
        $this->assertSame('2', $entries);
        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_extra_environment_variables_reach_the_tool_alongside_the_inherited_ones(): void
    {
        $result = (new ProcessRunner)->run($this->php('echo getenv("TXX_PROBE"), "|", getenv("PATH") === false ? "no-path" : "path";'), 10, 0, 4096, ['TXX_PROBE' => 'on']);

        $this->assertSame('on|path', $result->stdout);
    }

    public function test_missing_binary_is_a_failure_not_an_exception(): void
    {
        $result = (new ProcessRunner)->run(['/nonexistent/tool', 'x'], 5, 0, 1024);

        $this->assertSame(Reason::ToolFailed, $result->outcome());
    }
}
