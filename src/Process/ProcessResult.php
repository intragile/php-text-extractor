<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Process;

use IntrAgile\TextExtractor\Reason;

final class ProcessResult
{
    public function __construct(
        public readonly ?int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $timedOut,
        public readonly bool $stdoutTruncated,
    ) {}

    /**
     * Classifies the run; null means it succeeded.
     *
     * Exit status zero is not enough: Tesseract, for one, exits 0 with empty output when an
     * allocation fails under the memory cap. Reading that as "no text" would silently drop the
     * file's content, so empty output paired with diagnostics on stderr counts as a failure.
     */
    public function outcome(): ?Reason
    {
        if ($this->timedOut) {
            return Reason::ToolTimeout;
        }

        if ($this->exitCode !== 0) {
            return Reason::ToolFailed;
        }

        if (trim($this->stdout) === '' && trim($this->stderr) !== '') {
            return Reason::ToolFailed;
        }

        return null;
    }
}
