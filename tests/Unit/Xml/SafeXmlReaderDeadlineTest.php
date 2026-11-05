<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Xml;

use IntrAgile\TextExtractor\Support\Deadline;
use IntrAgile\TextExtractor\Support\ParseTimedOut;
use IntrAgile\TextExtractor\Tests\Support\FakeClock;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\Xml\SafeXmlReader;

final class SafeXmlReaderDeadlineTest extends TestCase
{
    public function test_reading_past_the_deadline_throws_and_restores_the_libxml_error_mode(): void
    {
        $previous = libxml_use_internal_errors(false);

        try {
            $deadline = Deadline::start(1, FakeClock::expiringAfter(1)->closure(), 3);
            $reader = SafeXmlReader::fromString('<r><a/><b/><c/><d/></r>', $deadline);
            $steps = 0;

            try {
                while ($reader->read()) {
                    $steps++;
                }

                $this->fail('The deadline was not enforced.');
            } catch (ParseTimedOut) {
                $this->assertSame(2, $steps);
                $this->assertFalse(libxml_use_internal_errors());
            }
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    public function test_skipping_a_subtree_counts_every_step(): void
    {
        $deadline = Deadline::start(1, FakeClock::expiringAfter(1)->closure(), 50);
        $reader = SafeXmlReader::fromFile(Fixtures::file('<r><big>'.str_repeat('<x/>', 100).'</big><after/></r>', 'xml'), $deadline);
        $reader->read();
        $reader->read();

        $this->expectException(ParseTimedOut::class);

        $reader->next();
    }

    public function test_a_reader_without_a_deadline_is_unlimited(): void
    {
        $reader = SafeXmlReader::fromString('<r>'.str_repeat('<x/>', 10000).'</r>');
        $steps = 0;

        while ($reader->read()) {
            $steps++;
        }

        // The root element, its children and the closing root tag.
        $this->assertSame(10002, $steps);
    }
}
