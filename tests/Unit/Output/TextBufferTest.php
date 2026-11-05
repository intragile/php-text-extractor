<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Output;

use IntrAgile\TextExtractor\Output\TextBuffer;
use PHPUnit\Framework\TestCase;

final class TextBufferTest extends TestCase
{
    public function test_cuts_on_a_utf8_boundary(): void
    {
        $buffer = new TextBuffer(5);

        $this->assertFalse($buffer->append('ááá'));
        $this->assertSame('áá', $buffer->toString());
        $this->assertTrue($buffer->isTruncated());
        $this->assertTrue($buffer->isFull());
    }

    public function test_append_returns_false_once_full(): void
    {
        $buffer = new TextBuffer(3);

        $this->assertTrue($buffer->append('ab'));
        $this->assertFalse($buffer->isTruncated());
        $this->assertFalse($buffer->append('cd'));
        $this->assertFalse($buffer->append('ef'));
        $this->assertSame('abc', $buffer->toString());
        $this->assertTrue($buffer->isTruncated());
    }

    public function test_exact_fit_is_not_truncated(): void
    {
        $buffer = new TextBuffer(4);

        $this->assertTrue($buffer->append('abcd'));
        $this->assertFalse($buffer->isTruncated());
        $this->assertTrue($buffer->isFull());
        $this->assertSame('abcd', $buffer->toString());
    }

    public function test_empty_append_after_full_is_not_truncation(): void
    {
        $buffer = new TextBuffer(2);
        $buffer->append('ab');

        $this->assertTrue($buffer->append(''));
        $this->assertFalse($buffer->isTruncated());
    }
}
