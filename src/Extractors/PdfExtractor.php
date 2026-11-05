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
 * Extracts the text layer of a PDF with poppler's pdftotext.
 *
 * The tool's diagnostics decide the outcome, so it runs without -q: silencing stderr would make a
 * crash under the memory cap look exactly like a PDF without text.
 */
final class PdfExtractor implements Extractor
{
    /** pdftotext's documented exit status for "error related to PDF permissions". */
    private const EXIT_PERMISSIONS = 3;

    /** pdftotext's exit status when the document cannot be opened. */
    private const EXIT_OPEN_FAILED = 1;

    private const MESSAGE_LIMIT = 500;

    /**
     * Syntax errors that mean the file itself is damaged: a broken cross-reference table or
     * trailer, a header that is not a PDF, or a compressed stream that cannot be decoded. Errors
     * in the content-stream operators (an unknown operator, a wrong argument count) are not among
     * them; poppler skips the operator and valid generators produce such pages routinely.
     */
    private const STRUCTURAL_ERROR = "/^Syntax Error\\b.*(xref|trailer|couldn't (read|find)|may not be a pdf file|end of file in \\w+ stream|compression method|bad block)/im";

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly ToolLocator $locator,
    ) {}

    public function supports(Format $format): bool
    {
        return $format === Format::Pdf;
    }

    public function extract(string $path, Format $format, Options $options): Result
    {
        $binary = $this->locator->find('pdftotext', $options->pdftotextBinary);

        if ($binary === null) {
            return Result::failed(Reason::MissingTool, Format::Pdf, 'pdftotext (poppler-utils) is not installed or not executable.');
        }

        // One byte over the limit is enough to detect truncation; TextBuffer then cuts on a
        // character boundary instead of leaving half of a multi-byte character behind.
        $stdoutLimit = $options->maxOutputBytes < PHP_INT_MAX ? $options->maxOutputBytes + 1 : PHP_INT_MAX;

        $process = $this->runner->run(
            [$binary, '-enc', 'UTF-8', $path, '-'],
            $options->processTimeout,
            $options->processMemoryMb,
            $stdoutLimit,
        );

        if ($process->timedOut) {
            return Result::failed(Reason::ToolTimeout, Format::Pdf, sprintf('pdftotext did not finish within %d seconds.', $options->processTimeout));
        }

        if ($process->exitCode !== 0) {
            return $this->classifyFailure($process);
        }

        // pdftotext ends every page with a form feed; a blank line keeps the page boundary visible
        // after normalisation strips control characters.
        $text = str_replace("\f", "\n\n", $process->stdout);

        if (trim($text) === '') {
            return $this->classifyEmpty($process);
        }

        $buffer = new TextBuffer($options->maxOutputBytes);
        $buffer->append($text);

        return Result::extracted($buffer->toString(), Format::Pdf, $process->stdoutTruncated || $buffer->isTruncated());
    }

    private function classifyFailure(ProcessResult $process): Result
    {
        $message = $this->message($process);

        if (self::isResourceFailure($process->stderr)) {
            return Result::failed(Reason::ToolFailed, Format::Pdf, $message);
        }

        // A wrong or missing user password is reported with exit status 1, the same status as an
        // unreadable file, so stderr has to be checked before the exit status.
        if (preg_match('/password|encrypt/i', $process->stderr) === 1 || $process->exitCode === self::EXIT_PERMISSIONS) {
            return Result::notExtractable(Reason::Encrypted, Format::Pdf, $message);
        }

        if ($process->exitCode === self::EXIT_OPEN_FAILED) {
            return Result::notExtractable(Reason::Corrupt, Format::Pdf, $message);
        }

        return Result::failed(Reason::ToolFailed, Format::Pdf, $message);
    }

    /**
     * No text with silent stderr is a document without a text layer. No text with diagnostics is
     * a failure, because that is how a run starved by the memory cap can look, unless every line
     * is one of poppler's parse diagnostics: those are deterministic, so retrying would never
     * help. Warnings and content-stream errors keep the file "no text layer"; only a structural
     * error means the document could not be read and the file is corrupt.
     */
    private function classifyEmpty(ProcessResult $process): Result
    {
        $stderr = trim($process->stderr);

        if ($stderr === '') {
            return Result::empty(Format::Pdf, Reason::NoTextLayer);
        }

        if (! self::isResourceFailure($stderr) && self::onlyParseErrors($stderr)) {
            return preg_match(self::STRUCTURAL_ERROR, $stderr) === 1
                ? Result::notExtractable(Reason::Corrupt, Format::Pdf, $this->message($process))
                : Result::empty(Format::Pdf, Reason::NoTextLayer);
        }

        return Result::failed(Reason::ToolFailed, Format::Pdf, $this->message($process));
    }

    private static function onlyParseErrors(string $stderr): bool
    {
        foreach (preg_split('/\R/', $stderr) ?: [] as $line) {
            if (trim($line) !== '' && preg_match('/^Syntax (Error|Warning)\b/', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function isResourceFailure(string $stderr): bool
    {
        return preg_match('/out of memory|bad_alloc|cannot allocate/i', $stderr) === 1;
    }

    private function message(ProcessResult $process): string
    {
        $stderr = trim($process->stderr);

        if ($stderr === '') {
            return sprintf('pdftotext exited with status %s.', $process->exitCode ?? 'unknown');
        }

        return sprintf(
            'pdftotext exited with status %s: %s',
            $process->exitCode ?? 'unknown',
            mb_strcut($stderr, 0, self::MESSAGE_LIMIT, 'UTF-8'),
        );
    }
}
