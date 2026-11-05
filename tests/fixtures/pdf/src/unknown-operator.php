<?php

declare(strict_types=1);

// Writes a valid one-page PDF whose uncompressed content stream draws a line and then uses an
// operator that does not exist, so pdftotext reports a syntax error but finds no text.
// Usage: php unknown-operator.php unknown-operator.pdf

[, $target] = $argv;
$content = '0 0 m 10 10 l S foo';
$objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R /Resources << >> >>',
    '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream",
];

$pdf = "%PDF-1.4\n";
$offsets = [];

foreach ($objects as $index => $object) {
    $offsets[] = strlen($pdf);
    $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
}

$xref = strlen($pdf);
$pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

foreach ($offsets as $offset) {
    $pdf .= sprintf("%010d 00000 n \n", $offset);
}

$pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
file_put_contents($target, $pdf);
