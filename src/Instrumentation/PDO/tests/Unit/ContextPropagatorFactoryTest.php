<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\PDO\Unit;

use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\LoggerHolder;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use OpenTelemetry\Contrib\Instrumentation\PDO\ContextPropagatorFactory;
use OpenTelemetry\SDK\Common\Configuration\KnownValues;
use PHPUnit\Framework\TestCase;

class ContextPropagatorFactoryTest extends TestCase
{
    #[\Override]
    public function setUp(): void
    {
        LoggerHolder::disable();
        Logging::disable();
    }

    /**
     * @psalm-suppress ArgumentTypeCoercion
     * @dataProvider propagatorsProvider
     */
    public function test_create(string $propagators, ?string $expected): void
    {
        putenv('OTEL_PHP_INSTRUMENTATION_PDO_CONTEXT_PROPAGATORS=' . $propagators);
        $propagator = (new ContextPropagatorFactory())->create();
        if ($expected === null) {
            $this->assertNull($propagator);
        } else {
            $this->assertInstanceOf($expected, $propagator);
        }
        putenv('OTEL_PHP_INSTRUMENTATION_PDO_CONTEXT_PROPAGATORS');
    }

    public static function propagatorsProvider(): array
    {
        return [
            [KnownValues::VALUE_BAGGAGE, BaggagePropagator::class],
            [KnownValues::VALUE_TRACECONTEXT, TraceContextPropagator::class],
            [KnownValues::VALUE_NONE, null],
            [sprintf('%s,%s', KnownValues::VALUE_TRACECONTEXT, KnownValues::VALUE_BAGGAGE), MultiTextMapPropagator::class],
            ['', null],
            ['invalid', null],
        ];
    }
}
