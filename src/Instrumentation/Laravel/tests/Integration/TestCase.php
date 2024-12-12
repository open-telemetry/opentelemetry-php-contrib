<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration;

use ArrayObject;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\TestStorage;
use Orchestra\Testbench\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

abstract class TestCase extends BaseTestCase
{
    protected ArrayObject $storage;

    #[Before]
    public function setUpTestStorage(): void
    {
        $this->storage = TestStorage::getInstance();
    }

    #[After]
    public function tearDownTestStorage(): void
    {
        TestStorage::reset();
    }
}
