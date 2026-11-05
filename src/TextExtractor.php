<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor;

use Closure;
use IntrAgile\TextExtractor\Archive\ArchiveLimitExceeded;
use IntrAgile\TextExtractor\Contracts\Extractor;
use IntrAgile\TextExtractor\Detection\FormatDetector;
use IntrAgile\TextExtractor\Exception\CorruptInput;
use IntrAgile\TextExtractor\Extractors\HtmlExtractor;
use IntrAgile\TextExtractor\Extractors\ImageExtractor;
use IntrAgile\TextExtractor\Extractors\Ooxml\DocxExtractor;
use IntrAgile\TextExtractor\Extractors\Ooxml\PptxExtractor;
use IntrAgile\TextExtractor\Extractors\Ooxml\XlsxExtractor;
use IntrAgile\TextExtractor\Extractors\OpenDocumentExtractor;
use IntrAgile\TextExtractor\Extractors\PdfExtractor;
use IntrAgile\TextExtractor\Extractors\PlainTextExtractor;
use IntrAgile\TextExtractor\Extractors\RtfExtractor;
use IntrAgile\TextExtractor\Extractors\XmlExtractor;
use IntrAgile\TextExtractor\Output\TextNormalizer;
use IntrAgile\TextExtractor\Process\ProcessRunner;
use IntrAgile\TextExtractor\Process\ToolLocator;
use IntrAgile\TextExtractor\Support\Deadline;
use IntrAgile\TextExtractor\Support\DeadlineAwareExtractor;
use IntrAgile\TextExtractor\Support\ParseTimedOut;
use InvalidArgumentException;
use Throwable;

/**
 * Extracts plain text from a file, a string or a stream.
 *
 * Problems with the input never surface as exceptions: every outcome, including bugs in an
 * extractor, is reported as a Result so that batch jobs can record it and move on.
 */
final class TextExtractor
{
    private const TEMP_PREFIX = 'txx_';

    private Options $options;

    private FormatDetector $detector;

    /** @var list<Extractor> */
    private array $extractors;

    /** @var (Closure(): int)|null */
    private ?Closure $clock = null;

    /**
     * @param  list<Extractor>|null  $extractors  Tried in order; null selects the built-in set.
     */
    public function __construct(?Options $options = null, ?FormatDetector $detector = null, ?array $extractors = null)
    {
        $this->options = $options ?? new Options;
        $this->detector = $detector ?? new FormatDetector;
        $this->extractors = $extractors ?? self::defaultExtractors();
    }

    public static function create(?Options $options = null): self
    {
        return new self($options);
    }

    /**
     * @return list<Extractor>
     */
    public static function defaultExtractors(?ProcessRunner $runner = null, ?ToolLocator $locator = null): array
    {
        $runner ??= new ProcessRunner;
        $locator ??= new ToolLocator;

        return [
            new PlainTextExtractor,
            new XmlExtractor,
            new HtmlExtractor,
            new RtfExtractor,
            new DocxExtractor,
            new PptxExtractor,
            new XlsxExtractor,
            new OpenDocumentExtractor,
            new PdfExtractor($runner, $locator),
            new ImageExtractor($runner, $locator),
        ];
    }

    /**
     * Returns a copy that tries the given extractor before all others.
     */
    public function withExtractor(Extractor $extractor): self
    {
        $copy = clone $this;
        $copy->extractors = [$extractor, ...$this->extractors];

        return $copy;
    }

    public function withOptions(Options $options): self
    {
        $copy = clone $this;
        $copy->options = $options;

        return $copy;
    }

    /**
     * Returns a copy that measures the parse time limit with the given clock.
     *
     * @internal Exists so that tests can exercise maxParseSeconds without waiting.
     *
     * @param  Closure(): int  $clock  Monotonic nanoseconds.
     */
    public function withClock(Closure $clock): self
    {
        $copy = clone $this;
        $copy->clock = $clock;

        return $copy;
    }

    public function options(): Options
    {
        return $this->options;
    }

    /**
     * @param  string|null  $filenameHint  Original file name when $path is a temporary upload.
     */
    public function extract(string $path, ?string $filenameHint = null): Result
    {
        $deadline = Deadline::fromOptions($this->options, $this->clock);

        if (! is_file($path) || ! is_readable($path)) {
            return Result::failed(Reason::Unreadable, null, 'The file does not exist or cannot be read.');
        }

        $size = @filesize($path);

        if ($size === false) {
            return Result::failed(Reason::Unreadable, null, 'The file size cannot be determined.');
        }

        if ($size > $this->options->maxInputBytes) {
            return Result::notExtractable(Reason::TooLarge, null, sprintf('The file is %d bytes; the limit is %d.', $size, $this->options->maxInputBytes));
        }

        $claimed = $this->detector->contradictedFormat($path, $filenameHint);

        if ($claimed !== null) {
            return Result::notExtractable(Reason::Corrupt, $claimed, sprintf('The file name says %s, but the content is empty or plain text.', $claimed->value));
        }

        $format = $this->detector->detect($path, $filenameHint);

        if ($format === null) {
            return Result::notExtractable(Reason::UnsupportedFormat, null, 'The file format is not recognised.');
        }

        // External tools run in their own working directory, so they need an absolute path.
        $absolute = realpath($path);

        if ($absolute === false) {
            return Result::failed(Reason::Unreadable, $format, 'The file path cannot be resolved.');
        }

        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($format)) {
                return $this->run($extractor, $absolute, $format, $deadline);
            }
        }

        return Result::notExtractable(Reason::UnsupportedFormat, $format, sprintf('No extractor is registered for "%s".', $format->value));
    }

    public function extractFromString(string $contents, ?string $filenameHint = null): Result
    {
        if (strlen($contents) > $this->options->maxInputBytes) {
            return Result::notExtractable(Reason::TooLarge, null, sprintf('The input is %d bytes; the limit is %d.', strlen($contents), $this->options->maxInputBytes));
        }

        return $this->withTemporaryFile(
            static fn (string $path): ?string => file_put_contents($path, $contents) === strlen($contents)
                ? null
                : 'The input cannot be written to a temporary file.',
            $filenameHint,
        );
    }

    /**
     * Reads the stream from its current position to the end.
     *
     * @param  mixed  $stream  An open stream resource; anything else is rejected.
     */
    public function extractFromStream($stream, ?string $filenameHint = null): Result
    {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('extractFromStream() expects an open stream resource.');
        }

        $limit = $this->options->maxInputBytes;
        // One byte over the limit is enough to tell an oversized stream from one exactly at it.
        $readLimit = $limit < PHP_INT_MAX ? $limit + 1 : PHP_INT_MAX;
        $tooLarge = false;

        return $this->withTemporaryFile(
            static function (string $path) use ($stream, $limit, $readLimit, &$tooLarge): ?string {
                $target = fopen($path, 'wb');

                if ($target === false) {
                    return 'The input cannot be written to a temporary file.';
                }

                try {
                    $copied = stream_copy_to_stream($stream, $target, $readLimit);
                } finally {
                    fclose($target);
                }

                if ($copied === false) {
                    return 'The input stream cannot be read.';
                }

                $tooLarge = $copied > $limit;

                return null;
            },
            $filenameHint,
            static fn (): bool => $tooLarge,
        );
    }

    /**
     * Copies the input into a temporary file and extracts from that.
     *
     * The IO runs under a local error handler: applications commonly turn warnings into
     * exceptions, and a stream that cannot be read is a problem with the input, which must come
     * back as a Result rather than escape as an ErrorException.
     *
     * @param  callable(string): ?string  $fill  Returns null on success, otherwise the failure message.
     * @param  (callable(): bool)|null  $isTooLarge
     */
    private function withTemporaryFile(callable $fill, ?string $filenameHint, ?callable $isTooLarge = null): Result
    {
        $path = false;
        set_error_handler(static fn (): bool => true);

        try {
            $path = tempnam(sys_get_temp_dir(), self::TEMP_PREFIX);
            $failure = $path === false ? 'A temporary file cannot be created.' : $fill($path);
        } catch (Throwable $exception) {
            $failure = 'The input cannot be copied to a temporary file: '.$exception->getMessage();
        } finally {
            restore_error_handler();
        }

        try {
            if ($failure !== null || $path === false) {
                return Result::failed(Reason::Unreadable, null, $failure);
            }

            if ($isTooLarge !== null && $isTooLarge()) {
                return Result::notExtractable(Reason::TooLarge, null, sprintf('The input exceeds the limit of %d bytes.', $this->options->maxInputBytes));
            }

            return $this->extract($path, $filenameHint);
        } finally {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function run(Extractor $extractor, string $path, Format $format, Deadline $deadline): Result
    {
        try {
            $result = $extractor instanceof DeadlineAwareExtractor
                ? $extractor->extractWithin($path, $format, $this->options, $deadline)
                : $extractor->extract($path, $format, $this->options);
        } catch (ParseTimedOut $exception) {
            // Whatever text was gathered before the limit is incomplete in an unknown way, so it
            // is dropped rather than returned as if the document ended there.
            return Result::failed(Reason::ParseTimeout, $format, $exception->getMessage());
        } catch (ArchiveLimitExceeded $exception) {
            return Result::notExtractable(Reason::ArchiveLimitExceeded, $format, $exception->getMessage());
        } catch (CorruptInput $exception) {
            return Result::notExtractable(Reason::Corrupt, $format, $exception->getMessage());
        } catch (Throwable $exception) {
            return Result::failed(Reason::InternalError, $format, $exception::class.': '.$exception->getMessage());
        }

        if ($result->status !== Status::Extracted) {
            return $result;
        }

        $text = (new TextNormalizer($this->options->fallbackEncoding))->normalize($result->text);
        $truncated = $result->truncated;

        if (strlen($text) > $this->options->maxOutputBytes) {
            $text = rtrim(mb_strcut($text, 0, $this->options->maxOutputBytes, 'UTF-8'));
            $truncated = true;
        }

        // Text that did not fit is still text: reporting it as Empty would tell the caller the
        // document has nothing in it.
        if ($text === '' && ! $truncated) {
            return Result::empty($result->format ?? $format);
        }

        return Result::extracted($text, $result->format ?? $format, $truncated);
    }
}
