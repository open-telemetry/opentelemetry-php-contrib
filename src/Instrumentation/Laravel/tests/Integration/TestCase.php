<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as LogInMemoryExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as SpanInMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

/** @psalm-suppress UnusedClass */
abstract class TestCase extends BaseTestCase
{
    protected ScopeInterface $scope;
    /** @var ArrayObject|ImmutableSpan[] $storage */
    protected ArrayObject $storage;
    protected ArrayObject $loggerStorage;
    protected TracerProvider $tracerProvider;
    protected LoggerProvider $loggerProvider;

    public function setUp(): void
    {
        parent::setUp();

        $this->storage = new ArrayObject();
        $this->tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new SpanInMemoryExporter($this->storage),
            ),
        );

        $this->loggerProvider = new LoggerProvider(
            new SimpleLogRecordProcessor(
                new LogInMemoryExporter($this->storage),
            ),
            new InstrumentationScopeFactory(Attributes::factory())
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($this->tracerProvider)
            ->withLoggerProvider($this->loggerProvider)
            ->activate();
    }

    public function tearDown(): void
    {
        parent::tearDown();

        $this->scope->detach();
    }

    /**
     * Assert that the span kinds match the expected kinds.
     *
     * @param array<int> $expectedKinds Array of expected span kinds
     * @param string $message Optional message to display on failure
     */
    protected function assertSpanKinds(array $expectedKinds, string $message = ''): void
    {
        $actualSpans = array_filter(
            iterator_to_array($this->storage),
            function ($item) {
                return $item instanceof ImmutableSpan;
            }
        );

        $actualKinds = array_map(function (ImmutableSpan $span) {
            return $span->getKind();
        }, $actualSpans);

        $this->assertEquals(
            $expectedKinds,
            $actualKinds,
            $message ?: 'Span kinds do not match expected values'
        );
    }

    /**
     * Get all spans from the storage.
     *
     * @return array<\OpenTelemetry\SDK\Trace\ImmutableSpan>
     */
    protected function getSpans(): array
    {
        return array_filter(
            iterator_to_array($this->storage),
            function ($item) {
                return $item instanceof ImmutableSpan;
            }
        );
    }

    /**
     * Assert that the spans match the expected spans.
     *
     * @param array<array<string, mixed>> $expectedSpans Array of expected span properties
     * @param string $message Optional message to display on failure
     */
    protected function assertSpans(array $expectedSpans, string $message = ''): void
    {
        $actualSpans = $this->getSpans();

        $this->assertCount(
            count($expectedSpans),
            $actualSpans,
            $message ?: sprintf(
                'Expected %d spans, got %d',
                count($expectedSpans),
                count($actualSpans)
            )
        );

        foreach ($expectedSpans as $index => $expected) {
            $actual = $actualSpans[$index];
            
            if (isset($expected['name'])) {
                $this->assertEquals(
                    $expected['name'],
                    $actual->getName(),
                    $message ?: sprintf('Span %d name mismatch', $index)
                );
            }

            if (isset($expected['attributes'])) {
                $this->assertEquals(
                    $expected['attributes'],
                    $actual->getAttributes()->toArray(),
                    $message ?: sprintf('Span %d attributes mismatch', $index)
                );
            }

            if (isset($expected['kind'])) {
                $this->assertEquals(
                    $expected['kind'],
                    $actual->getKind(),
                    $message ?: sprintf('Span %d kind mismatch', $index)
                );
            }

            if (isset($expected['status'])) {
                $this->assertEquals(
                    $expected['status'],
                    $actual->getStatus()->getCode(),
                    $message ?: sprintf('Span %d status mismatch', $index)
                );
            }
        }
    }

    protected function getTraceStructure(): array
    {
        // Filter out log records and only keep spans
        $spans = array_filter(
            iterator_to_array($this->storage),
            function ($item) {
                return $item instanceof ImmutableSpan;
            }
        );

        $spanMap = [];
        $rootSpans = [];

        // First pass: create a map of all spans by their span ID
        foreach ($spans as $span) {
            $spanId = $span->getSpanId();
            $spanMap[$spanId] = [
                'span' => $span,
                'children' => [],
            ];
        }

        // Second pass: build the tree structure
        foreach ($spans as $span) {
            $spanId = $span->getSpanId();
            $parentId = $span->getParentSpanId();

            if ($parentId === null || !isset($spanMap[$parentId])) {
                $rootSpans[] = $spanId;
            } else {
                $spanMap[$parentId]['children'][] = $spanId;
            }
        }

        return [
            'rootSpans' => $rootSpans,
            'spanMap' => $spanMap,
        ];
    }

    /**
     * Get all log records from the storage.
     *
     * @return array<\OpenTelemetry\SDK\Logs\ReadWriteLogRecord>
     */
    protected function getLogRecords(): array
    {
        return array_filter(
            iterator_to_array($this->storage),
            function ($item) {
                return $item instanceof \OpenTelemetry\SDK\Logs\ReadWriteLogRecord;
            }
        );
    }

    /**
     * Assert that the log records match the expected records.
     *
     * @param array<array<string, mixed>> $expectedRecords Array of expected log record properties
     * @param string $message Optional message to display on failure
     */
    protected function assertLogRecords(array $expectedRecords, string $message = ''): void
    {
        $logRecords = $this->getLogRecords();

        $this->assertCount(
            count($expectedRecords),
            $logRecords,
            $message ?: sprintf(
                'Expected %d log records, got %d',
                count($expectedRecords),
                count($logRecords)
            )
        );

        foreach ($expectedRecords as $index => $expected) {
            $actual = $logRecords[$index];
            
            if (isset($expected['body'])) {
                $this->assertEquals(
                    $expected['body'],
                    $actual->getBody(),
                    $message ?: sprintf('Log record %d body mismatch', $index)
                );
            }

            if (isset($expected['severity_text'])) {
                $this->assertEquals(
                    $expected['severity_text'],
                    $actual->getSeverityText(),
                    $message ?: sprintf('Log record %d severity text mismatch', $index)
                );
            }

            if (isset($expected['severity_number'])) {
                $this->assertEquals(
                    $expected['severity_number'],
                    $actual->getSeverityNumber(),
                    $message ?: sprintf('Log record %d severity number mismatch', $index)
                );
            }

            if (isset($expected['attributes'])) {
                $actualAttributes = $actual->getAttributes()->toArray();
                foreach ($expected['attributes'] as $key => $value) {
                    $this->assertArrayHasKey(
                        $key,
                        $actualAttributes,
                        $message ?: sprintf('Missing attribute %s for log record %d', $key, $index)
                    );
                    $this->assertEquals(
                        $value,
                        $actualAttributes[$key],
                        $message ?: sprintf('Attribute %s mismatch for log record %d', $key, $index)
                    );
                }
            }
        }
    }

    /**
     * Assert that the trace structure matches the expected hierarchy.
     *
     * @param array<array<string, mixed>> $expectedStructure Array defining the expected trace structure
     * @param string $message Optional message to display on failure
     */
    protected function assertTraceStructure(array $expectedStructure, string $message = ''): void
    {
        $actualStructure = $this->getTraceStructure();
        $spans = $this->getSpans();

        // Helper function to recursively verify span structure
        $verifySpan = function (array $expected, ImmutableSpan $actual, array $actualStructure, string $message) use (&$verifySpan): void {
            // Verify span properties
            if (isset($expected['name'])) {
                $this->assertEquals(
                    $expected['name'],
                    $actual->getName(),
                    $message ?: sprintf('Span name mismatch for span %s', $actual->getSpanId())
                );
            }

            if (isset($expected['attributes'])) {
                $actualAttributes = $actual->getAttributes()->toArray();
                foreach ($expected['attributes'] as $key => $value) {
                    $this->assertArrayHasKey(
                        $key,
                        $actualAttributes,
                        $message ?: sprintf('Missing attribute %s for span %s', $key, $actual->getSpanId())
                    );
                    $this->assertEquals(
                        $value,
                        $actualAttributes[$key],
                        $message ?: sprintf('Attribute %s mismatch for span %s', $key, $actual->getSpanId())
                    );
                }
            }

            if (isset($expected['kind'])) {
                $this->assertEquals(
                    $expected['kind'],
                    $actual->getKind(),
                    $message ?: sprintf('Span kind mismatch for span %s', $actual->getSpanId())
                );
            }

            // Verify children if present
            if (isset($expected['children'])) {
                $children = $actualStructure['spanMap'][$actual->getSpanId()]['children'] ?? [];
                $this->assertCount(
                    count($expected['children']),
                    $children,
                    $message ?: sprintf('Expected %d children for span %s, got %d', 
                        count($expected['children']), 
                        $actual->getSpanId(),
                        count($children)
                    )
                );

                foreach ($expected['children'] as $index => $expectedChild) {
                    $childId = $children[$index];
                    $actualChild = $actualStructure['spanMap'][$childId]['span'];
                    $verifySpan($expectedChild, $actualChild, $actualStructure, $message);
                }
            }
        };

        // Start verification from root spans
        foreach ($expectedStructure as $index => $expectedRoot) {
            $this->assertArrayHasKey(
                $index,
                $actualStructure['rootSpans'],
                $message ?: sprintf('Expected root span at index %d', $index)
            );

            $rootId = $actualStructure['rootSpans'][$index];
            $actualRoot = $actualStructure['spanMap'][$rootId]['span'];
            $verifySpan($expectedRoot, $actualRoot, $actualStructure, $message);
        }
    }
}
