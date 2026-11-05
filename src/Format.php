<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor;

enum Format: string
{
    case Txt = 'txt';
    case Markdown = 'md';
    case Csv = 'csv';
    case Tsv = 'tsv';
    case Json = 'json';
    case Xml = 'xml';
    case Html = 'html';
    case Rtf = 'rtf';
    case Docx = 'docx';
    case Xlsx = 'xlsx';
    case Pptx = 'pptx';
    case Odt = 'odt';
    case Ods = 'ods';
    case Odp = 'odp';
    case Pdf = 'pdf';
    case Png = 'png';
    case Jpeg = 'jpeg';
    case Tiff = 'tiff';
    case Bmp = 'bmp';
    case Gif = 'gif';
    case Webp = 'webp';

    public function isImage(): bool
    {
        return match ($this) {
            self::Png, self::Jpeg, self::Tiff, self::Bmp, self::Gif, self::Webp => true,
            default => false,
        };
    }
}
