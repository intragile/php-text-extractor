<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        Fixtures::cleanup();

        parent::tearDown();
    }
}
