<?php

declare(strict_types=1);

// Writes a copy of a PDF whose first stream (the page content) has 38 bytes overwritten, leaving
// the cross-reference table intact. Usage: php damage.php text.pdf damaged-stream.pdf

[, $source, $target] = $argv;
$bytes = (string) file_get_contents($source);

if (preg_match('/stream\r?\n/', $bytes, $match, PREG_OFFSET_CAPTURE) !== 1) {
    fwrite(STDERR, "No stream found.\n");
    exit(1);
}

$start = $match[0][1] + strlen($match[0][0]);
file_put_contents($target, substr_replace($bytes, str_repeat('Z', 38), $start + 2, 38));
