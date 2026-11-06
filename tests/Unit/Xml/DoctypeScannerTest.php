<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Xml;

use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\Xml\DoctypeScanner;
use PHPUnit\Framework\Attributes\DataProvider;

final class DoctypeScannerTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function prologs(): array
    {
        return [
            'external DTD only' => ['<?xml version="1.0"?><!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd"><plist>', false],
            'system identifier only' => ["<!DOCTYPE note SYSTEM 'note.dtd'>\n<note>", false],
            'bracket inside a quoted identifier' => ['<!DOCTYPE x SYSTEM "a[b].dtd"><x>', false],
            'name only' => ['<!DOCTYPE x><x>', false],
            'after a comment and a processing instruction' => ['<?xml version="1.0"?><!-- <!DOCTYPE fake [ ]> --><?pi [ ?><!DOCTYPE x SYSTEM "x.dtd"><x>', false],
            'entity declaration' => ['<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>', true],
            'empty subset' => ['<!DOCTYPE x []><x>', true],
            'subset after an external identifier' => ['<!DOCTYPE x SYSTEM "x.dtd" [<!ENTITY a "b">]><x>', true],
            'whitespace before the bracket' => ["<!DOCTYPE x\n  [\n]><x>", true],
            'UTF-16 with a subset' => ["\xFF\xFE".mb_convert_encoding('<!DOCTYPE x [<!ENTITY a "b">]><x/>', 'UTF-16LE', 'UTF-8'), true],
            'UTF-16 external only' => ["\xFE\xFF".mb_convert_encoding('<!DOCTYPE x SYSTEM "x.dtd"><x/>', 'UTF-16BE', 'UTF-8'), false],
            // When the declaration cannot be seen completely, the document is treated as unsafe.
            'declaration cut off' => ['<!DOCTYPE x SYSTEM "x.dtd"', true],
            'no declaration in the captured bytes' => ['<?xml version="1.0"?><!-- long comment', true],
            'unterminated quote' => ['<!DOCTYPE x SYSTEM "x.dtd><x>', true],
        ];
    }

    #[DataProvider('prologs')]
    public function test_internal_subsets_are_found_in_the_raw_prolog(string $prolog, bool $expected): void
    {
        $this->assertSame($expected, DoctypeScanner::mayHaveInternalSubset($prolog));
    }
}
