<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Psr14\Tests\Fixture;

/**
 * @internal
 */
final class SampleEventClass
{
    /** @psalm-suppress PossiblyUnusedProperty */
    public string $item;

    public function __construct(string $item = 'test')
    {
        $this->item = $item;
    }
}
