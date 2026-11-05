<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Output;

use IntrAgile\TextExtractor\Output\LayoutWriter;
use IntrAgile\TextExtractor\Output\TextBuffer;
use IntrAgile\TextExtractor\Tests\Support\TestCase;

final class LayoutWriterTest extends TestCase
{
    public function test_text_outside_tables_is_written_unchanged(): void
    {
        $buffer = new TextBuffer(100);
        $writer = new LayoutWriter($buffer);

        $writer->text(" a\tb ");
        $writer->tab();
        $writer->paragraphEnd();
        $writer->paragraphEnd();
        $writer->blockEnd();

        $this->assertSame(" a\tb \t\n\n", $buffer->toString());
    }

    public function test_unbalanced_table_events_are_tolerated(): void
    {
        $buffer = new TextBuffer(100);
        $writer = new LayoutWriter($buffer);

        $writer->tableEnd();
        $writer->rowEnd();
        $writer->cellStart();
        $writer->text('loose');
        $writer->tableStart();
        $writer->cellStart();
        $writer->text('a');
        $writer->cellStart();
        $writer->text('b');
        $writer->tableEnd();
        $writer->text('after');

        $this->assertSame("loose\na\tb\nafter", $buffer->toString());
    }

    public function test_full_buffer_is_reported(): void
    {
        $buffer = new TextBuffer(3);
        $writer = new LayoutWriter($buffer);

        $writer->tableStart();
        $writer->rowStart();
        $writer->cellStart();

        $this->assertFalse($writer->text('abcd'));
        $this->assertTrue($buffer->isTruncated());
    }
}
