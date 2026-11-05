<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Extractors;

use IntrAgile\TextExtractor\Contracts\Extractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Output\TextBuffer;
use IntrAgile\TextExtractor\Process\ProcessResult;
use IntrAgile\TextExtractor\Process\ProcessRunner;
use IntrAgile\TextExtractor\Process\ToolLocator;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;

/**
 * Recognises text in raster images with Tesseract OCR.
 */
final class ImageExtractor implements Extractor
{
    /**
     * Informational lines Tesseract may print on a successful run, depending on version and on
     * whether the "quiet" config is honoured. They must not turn a blank image into a failure.
     */
    private const BENIGN_DIAGNOSTICS = [
        '/^Estimating resolution as \d+$/',
        '/^Warning: Invalid resolution \d+ dpi\. Using \d+ instead\.$/',
        '/^Empty page!!$/',
        '/^Detected \d+ diacritics$/',
    ];

    private const MESSAGE_LIMIT = 1000;

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly ToolLocator $locator,
    ) {}

    public function supports(Format $format): bool
    {
        return $format->isImage();
    }

    public function extract(string $path, Format $format, Options $options): Result
    {
        // Tesseract's memory use follows the pixel count, not the file size: a few hundred KB of
        // JPEG can decode to hundreds of MB. Only headers are read, so the check is cheap; when
        // they cannot be parsed, Tesseract itself reports the problem.
        $tooLarge = ImageGeometry::oversized($path, $options->maxImagePixels);

        if ($tooLarge !== null) {
            return Result::notExtractable(Reason::ImageTooLarge, $format, $tooLarge);
        }

        $binary = $this->locator->find('tesseract', $options->tesseractBinary);

        if ($binary === null) {
            return Result::failed(Reason::MissingTool, $format, 'tesseract is not installed or not executable.');
        }

        // One byte over the limit is enough to detect truncation; TextBuffer then cuts on a
        // character boundary.
        $stdoutLimit = $options->maxOutputBytes < PHP_INT_MAX ? $options->maxOutputBytes + 1 : PHP_INT_MAX;

        $process = $this->runner->run(
            [$binary, $path, 'stdout', '-l', $options->ocrLanguages, 'quiet'],
            $options->processTimeout,
            $options->processMemoryMb,
            $stdoutLimit,
            // OpenMP reserves address space for one thread stack per core, so on a large host a
            // multi-threaded run can exceed the memory cap before reading a single pixel.
            ['OMP_THREAD_LIMIT' => '1'],
        );

        $stderr = $this->withoutBenignDiagnostics($process->stderr);
        $outcome = (new ProcessResult(
            $process->exitCode,
            $process->stdout,
            $stderr,
            $process->timedOut,
            $process->stdoutTruncated,
        ))->outcome();

        if ($outcome !== null) {
            return Result::failed($outcome, $format, $this->failureMessage($outcome, $process, $stderr));
        }

        if (trim($process->stdout) === '') {
            return Result::empty($format);
        }

        $buffer = new TextBuffer($options->maxOutputBytes);
        $buffer->append($process->stdout);

        return Result::extracted($buffer->toString(), $format, $buffer->isTruncated() || $process->stdoutTruncated);
    }

    private function withoutBenignDiagnostics(string $stderr): string
    {
        $kept = [];

        foreach (preg_split('/\R/', $stderr) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            foreach (self::BENIGN_DIAGNOSTICS as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    continue 2;
                }
            }

            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    private function failureMessage(Reason $outcome, ProcessResult $process, string $stderr): string
    {
        if ($outcome === Reason::ToolTimeout) {
            return 'tesseract did not finish within the time limit.';
        }

        $message = sprintf('tesseract exited with code %s', $process->exitCode === null ? 'unknown' : (string) $process->exitCode);

        if ($stderr !== '') {
            $message .= ': '.mb_strcut($stderr, 0, self::MESSAGE_LIMIT, 'UTF-8');
        }

        return $message;
    }
}
