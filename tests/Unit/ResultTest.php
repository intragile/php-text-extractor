<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit;

use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;
use IntrAgile\TextExtractor\Status;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase
{
    public function test_failed_rejects_a_reason_belonging_to_another_status(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Result::failed(Reason::Encrypted);
    }

    public function test_not_extractable_rejects_a_reason_belonging_to_another_status(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Result::notExtractable(Reason::ToolTimeout);
    }

    public function test_empty_rejects_a_reason_belonging_to_another_status(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Result::empty(Format::Pdf, Reason::Corrupt);
    }

    /**
     * @return \Generator<string, array{Reason, Status}>
     */
    public static function reasonStatusTable(): \Generator
    {
        yield 'unreadable' => [Reason::Unreadable, Status::Failed];
        yield 'missing_extension' => [Reason::MissingExtension, Status::Failed];
        yield 'missing_tool' => [Reason::MissingTool, Status::Failed];
        yield 'tool_timeout' => [Reason::ToolTimeout, Status::Failed];
        yield 'tool_failed' => [Reason::ToolFailed, Status::Failed];
        yield 'internal_error' => [Reason::InternalError, Status::Failed];
        yield 'unsupported_format' => [Reason::UnsupportedFormat, Status::NotExtractable];
        yield 'too_large' => [Reason::TooLarge, Status::NotExtractable];
        yield 'image_too_large' => [Reason::ImageTooLarge, Status::NotExtractable];
        yield 'encrypted' => [Reason::Encrypted, Status::NotExtractable];
        yield 'corrupt' => [Reason::Corrupt, Status::NotExtractable];
        yield 'archive_limit_exceeded' => [Reason::ArchiveLimitExceeded, Status::NotExtractable];
        yield 'no_text_layer' => [Reason::NoTextLayer, Status::Empty];
    }

    #[DataProvider('reasonStatusTable')]
    public function test_every_reason_maps_to_the_documented_status(Reason $reason, Status $status): void
    {
        $this->assertSame($status, $reason->status());
    }

    public function test_the_table_covers_every_reason(): void
    {
        $covered = array_map(static fn (array $row): Reason => $row[0], iterator_to_array(self::reasonStatusTable()));

        $this->assertCount(count(Reason::cases()), $covered);
    }

    public function test_reason_values_are_stable_strings(): void
    {
        $this->assertSame('archive_limit_exceeded', Reason::ArchiveLimitExceeded->value);
        $this->assertSame('no_text_layer', Reason::NoTextLayer->value);
        $this->assertSame('not_extractable', Status::NotExtractable->value);
    }

    public function test_successful_and_retryable_flags(): void
    {
        $this->assertTrue(Status::Extracted->isSuccessful());
        $this->assertTrue(Status::Empty->isSuccessful());
        $this->assertFalse(Status::NotExtractable->isSuccessful());
        $this->assertFalse(Status::Failed->isSuccessful());

        $this->assertTrue(Status::Failed->isRetryable());
        $this->assertFalse(Status::NotExtractable->isRetryable());
        $this->assertFalse(Status::Empty->isRetryable());
        $this->assertFalse(Status::Extracted->isRetryable());
    }

    public function test_named_constructors_fill_the_properties(): void
    {
        $extracted = Result::extracted('hello', Format::Txt, true);
        $this->assertSame(Status::Extracted, $extracted->status);
        $this->assertSame('hello', $extracted->text);
        $this->assertSame(Format::Txt, $extracted->format);
        $this->assertNull($extracted->reason);
        $this->assertTrue($extracted->truncated);
        $this->assertTrue($extracted->isSuccessful());

        $empty = Result::empty(Format::Pdf, Reason::NoTextLayer);
        $this->assertSame(Status::Empty, $empty->status);
        $this->assertSame('', $empty->text);
        $this->assertSame(Reason::NoTextLayer, $empty->reason);

        $failed = Result::failed(Reason::ToolTimeout, Format::Pdf, 'took too long');
        $this->assertSame(Status::Failed, $failed->status);
        $this->assertSame('took too long', $failed->message);
        $this->assertTrue($failed->isRetryable());

        $refused = Result::notExtractable(Reason::Encrypted, Format::Pdf);
        $this->assertSame(Status::NotExtractable, $refused->status);
        $this->assertFalse($refused->isRetryable());
    }

    public function test_format_image_flag(): void
    {
        $this->assertTrue(Format::Png->isImage());
        $this->assertTrue(Format::Webp->isImage());
        $this->assertFalse(Format::Pdf->isImage());
        $this->assertSame('docx', Format::Docx->value);
    }
}
