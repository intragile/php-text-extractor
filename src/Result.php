<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor;

use InvalidArgumentException;

final class Result
{
    private function __construct(
        public readonly Status $status,
        public readonly string $text,
        public readonly ?Format $format,
        public readonly ?Reason $reason,
        public readonly ?string $message,
        public readonly bool $truncated,
    ) {}

    public static function extracted(string $text, Format $format, bool $truncated = false): self
    {
        return new self(Status::Extracted, $text, $format, null, null, $truncated);
    }

    public static function empty(Format $format, ?Reason $reason = null): self
    {
        if ($reason !== null) {
            self::assertBelongsTo($reason, Status::Empty);
        }

        return new self(Status::Empty, '', $format, $reason, null, false);
    }

    public static function notExtractable(Reason $reason, ?Format $format = null, ?string $message = null): self
    {
        self::assertBelongsTo($reason, Status::NotExtractable);

        return new self(Status::NotExtractable, '', $format, $reason, $message, false);
    }

    public static function failed(Reason $reason, ?Format $format = null, ?string $message = null): self
    {
        self::assertBelongsTo($reason, Status::Failed);

        return new self(Status::Failed, '', $format, $reason, $message, false);
    }

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }

    public function isRetryable(): bool
    {
        return $this->status->isRetryable();
    }

    private static function assertBelongsTo(Reason $reason, Status $status): void
    {
        if ($reason->status() !== $status) {
            throw new InvalidArgumentException(sprintf(
                'Reason "%s" belongs to status "%s", not "%s".',
                $reason->value,
                $reason->status()->value,
                $status->value,
            ));
        }
    }
}
