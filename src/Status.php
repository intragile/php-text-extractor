<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor;

/**
 * The outcome of an extraction.
 *
 * Empty and Failed are deliberately distinct: a photo with no text is a successful result, and
 * treating it as a failure (or a failure as "no text") silently loses content from search indexes.
 */
enum Status: string
{
    /** Text was found. */
    case Extracted = 'extracted';

    /** The file was processed successfully but contains no text. */
    case Empty = 'empty';

    /** The file can never be processed as it is; retrying will not help. */
    case NotExtractable = 'not_extractable';

    /** A technical problem occurred; the same file may succeed on a later attempt. */
    case Failed = 'failed';

    public function isSuccessful(): bool
    {
        return $this === self::Extracted || $this === self::Empty;
    }

    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }
}
