<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor;

/**
 * Machine-readable explanation of a result that is not plain success.
 *
 * Every reason belongs to exactly one status, so callers can branch on either.
 */
enum Reason: string
{
    case Unreadable = 'unreadable';
    case MissingExtension = 'missing_extension';
    case MissingTool = 'missing_tool';
    case ToolTimeout = 'tool_timeout';
    case ToolFailed = 'tool_failed';
    case InternalError = 'internal_error';
    case UnsupportedFormat = 'unsupported_format';
    case TooLarge = 'too_large';
    case ImageTooLarge = 'image_too_large';
    case Encrypted = 'encrypted';
    case Corrupt = 'corrupt';
    case ArchiveLimitExceeded = 'archive_limit_exceeded';
    case NoTextLayer = 'no_text_layer';

    public function status(): Status
    {
        return match ($this) {
            self::Unreadable,
            self::MissingExtension,
            self::MissingTool,
            self::ToolTimeout,
            self::ToolFailed,
            self::InternalError => Status::Failed,

            self::UnsupportedFormat,
            self::TooLarge,
            self::ImageTooLarge,
            self::Encrypted,
            self::Corrupt,
            self::ArchiveLimitExceeded => Status::NotExtractable,

            self::NoTextLayer => Status::Empty,
        };
    }
}
