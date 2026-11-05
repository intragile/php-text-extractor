<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Process;

use IntrAgile\TextExtractor\Process\ToolLocator;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;

final class ToolLocatorTest extends TestCase
{
    public function test_missing_tool_returns_null(): void
    {
        $this->assertNull((new ToolLocator)->find('definitely-not-a-real-tool-'.bin2hex(random_bytes(3))));
    }

    public function test_configured_non_executable_returns_null(): void
    {
        $path = Fixtures::file('not executable', 'sh');
        chmod($path, 0644);

        $this->assertNull((new ToolLocator)->find('pdftotext', $path));
    }

    public function test_configured_missing_path_returns_null(): void
    {
        $this->assertNull((new ToolLocator)->find('pdftotext', '/nonexistent/pdftotext'));
    }

    public function test_configured_executable_is_returned(): void
    {
        $path = Fixtures::file("#!/bin/sh\nexit 0\n", 'sh');
        chmod($path, 0755);

        $this->assertSame($path, (new ToolLocator)->find('pdftotext', $path));
    }

    public function test_tool_on_the_path_is_found(): void
    {
        $found = (new ToolLocator)->find('sh');

        $this->assertNotNull($found);
        $this->assertTrue(is_executable($found));
    }

    public function test_configured_bare_name_is_searched_on_the_path(): void
    {
        $this->assertNotNull((new ToolLocator)->find('pdftotext', 'sh'));
    }

    public function test_a_tool_installed_after_a_failed_lookup_is_found(): void
    {
        $locator = new ToolLocator;
        $path = Fixtures::path('sh');

        $this->assertNull($locator->find('pdftotext', $path));

        file_put_contents($path, "#!/bin/sh\nexit 0\n");
        chmod($path, 0755);

        $this->assertSame($path, $locator->find('pdftotext', $path));
    }
}
