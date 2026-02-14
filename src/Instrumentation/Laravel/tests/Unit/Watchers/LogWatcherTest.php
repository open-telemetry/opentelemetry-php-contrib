<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Unit\Watchers;

use ArrayObject;
use Exception;
use Illuminate\Log\Events\MessageLogged;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\LogWatcher;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use PHPUnit\Framework\TestCase;
use Stringable;

class LogWatcherTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;
    private LoggerProvider $loggerProvider;

    public function setUp(): void
    {
        parent::setUp();

        // Clear environment variable before each test
        putenv(LogWatcher::OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN);

        $this->storage = new ArrayObject();
        $this->loggerProvider = new LoggerProvider(
            new SimpleLogRecordProcessor(
                new InMemoryExporter($this->storage),
            ),
            new InstrumentationScopeFactory(Attributes::factory())
        );

        $this->scope = Configurator::create()
            ->withLoggerProvider($this->loggerProvider)
            ->activate();
    }

    public function tearDown(): void
    {
        parent::tearDown();

        $this->scope->detach();
        putenv(LogWatcher::OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN);
    }

    public function test_default_behavior_json_encodes_context(): void
    {
        $watcher = $this->createLogWatcher();

        $log = new MessageLogged('info', 'Test message', [
            'user_id' => '123',
            'action' => 'login',
        ]);

        $watcher->recordLog($log);

        $this->assertCount(1, $this->storage);
        $logRecord = $this->storage[0];

        $attributes = $logRecord->getAttributes()->toArray();
        $this->assertArrayHasKey('context', $attributes);
        $this->assertSame('{"user_id":"123","action":"login"}', $attributes['context']);
    }

    public function test_flattened_mode_spreads_context_as_individual_attributes(): void
    {
        putenv(LogWatcher::OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN . '=true');

        $watcher = $this->createLogWatcher();

        $log = new MessageLogged('info', 'Test message', [
            'user_id' => '123',
            'action' => 'login',
        ]);

        $watcher->recordLog($log);

        $this->assertCount(1, $this->storage);
        $logRecord = $this->storage[0];

        $attributes = $logRecord->getAttributes()->toArray();
        $this->assertArrayNotHasKey('context', $attributes);
        $this->assertSame('123', $attributes['user_id']);
        $this->assertSame('login', $attributes['action']);
    }

    public function test_flattened_mode_flattens_nested_arrays_with_dot_notation(): void
    {
        putenv(LogWatcher::OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN . '=true');

        $watcher = $this->createLogWatcher();

        $log = new MessageLogged('info', 'HTTP request', [
            'http' => [
                'method' => 'GET',
                'path' => '/users',
                'status' => 200,
            ],
            'duration_ms' => 123.45,
        ]);

        $watcher->recordLog($log);

        $this->assertCount(1, $this->storage);
        $logRecord = $this->storage[0];

        $attributes = $logRecord->getAttributes()->toArray();
        $this->assertArrayNotHasKey('context', $attributes);
        $this->assertArrayNotHasKey('http', $attributes);
        $this->assertSame('GET', $attributes['http.method']);
        $this->assertSame('/users', $attributes['http.path']);
        $this->assertSame(200, $attributes['http.status']);
        $this->assertSame(123.45, $attributes['duration_ms']);
    }

    public function test_flattened_mode_handles_deeply_nested_arrays(): void
    {
        putenv(LogWatcher::OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN . '=true');

        $watcher = $this->createLogWatcher();

        $log = new MessageLogged('info', 'Nested test', [
            'request' => [
                'headers' => [
                    'content_type' => 'application/json',
                    'accept' => 'application/json',
                ],
            ],
        ]);

        $watcher->recordLog($log);

        $attributes = $this->storage[0]->getAttributes()->toArray();
        $this->assertSame('application/json', $attributes['request.headers.content_type']);
        $this->assertSame('application/json', $attributes['request.headers.accept']);
    }

    public function test_exception_attributes_preserved_in_default_mode(): void
    {
        $watcher = $this->createLogWatcher();

        $exception = new Exception('Test exception');
        $log = new MessageLogged('error', 'Error occurred', [
            'exception' => $exception,
            'user_id' => '123',
        ]);

        $watcher->recordLog($log);

        $attributes = $this->storage[0]->getAttributes()->toArray();
        $this->assertArrayHasKey('context', $attributes);
        $this->assertSame('{"user_id":"123"}', $attributes['context']);
        $this->assertSame(Exception::class, $attributes['exception.type']);
        $this->assertSame('Test exception', $attributes['exception.message']);
        $this->assertArrayHasKey('exception.stacktrace', $attributes);
    }

    public function test_exception_attributes_preserved_in_flattened_mode(): void
    {
        putenv(LogWatcher::OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN . '=true');

        $watcher = $this->createLogWatcher();

        $exception = new Exception('Test exception');
        $log = new MessageLogged('error', 'Error occurred', [
            'exception' => $exception,
            'user_id' => '123',
        ]);

        $watcher->recordLog($log);

        $attributes = $this->storage[0]->getAttributes()->toArray();
        $this->assertArrayNotHasKey('context', $attributes);
        $this->assertSame('123', $attributes['user_id']);
        $this->assertSame(Exception::class, $attributes['exception.type']);
        $this->assertSame('Test exception', $attributes['exception.message']);
        $this->assertArrayHasKey('exception.stacktrace', $attributes);
    }

    public function test_flattened_mode_handles_stringable_objects(): void
    {
        putenv(LogWatcher::OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN . '=true');

        $watcher = $this->createLogWatcher();

        $stringable = new class() implements Stringable {
            public function __toString(): string
            {
                return 'stringable-value';
            }
        };

        $log = new MessageLogged('info', 'Test', [
            'object' => $stringable,
        ]);

        $watcher->recordLog($log);

        $attributes = $this->storage[0]->getAttributes()->toArray();
        $this->assertSame('stringable-value', $attributes['object']);
    }

    public function test_flattened_mode_handles_null_values(): void
    {
        putenv(LogWatcher::OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN . '=true');

        $watcher = $this->createLogWatcher();

        $log = new MessageLogged('info', 'Test', [
            'nullable' => null,
            'present' => 'value',
        ]);

        $watcher->recordLog($log);

        // array_filter removes null by default, so 'nullable' won't be present
        $attributes = $this->storage[0]->getAttributes()->toArray();
        $this->assertArrayNotHasKey('nullable', $attributes);
        $this->assertSame('value', $attributes['present']);
    }

    public function test_flattened_mode_handles_boolean_values(): void
    {
        putenv(LogWatcher::OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN . '=true');

        $watcher = $this->createLogWatcher();

        $log = new MessageLogged('info', 'Test', [
            'is_admin' => true,
            'is_guest' => false,
            'name' => 'test',
        ]);

        $watcher->recordLog($log);

        $attributes = $this->storage[0]->getAttributes()->toArray();
        $this->assertTrue($attributes['is_admin']);
        // false is filtered out by array_filter
        $this->assertArrayNotHasKey('is_guest', $attributes);
        $this->assertSame('test', $attributes['name']);
    }

    public function test_log_severity_is_set_correctly(): void
    {
        $watcher = $this->createLogWatcher();

        $log = new MessageLogged('warning', 'Warning message', []);

        $watcher->recordLog($log);

        $logRecord = $this->storage[0];
        $this->assertSame('warning', $logRecord->getSeverityText());
        $this->assertSame(13, $logRecord->getSeverityNumber());
    }

    public function test_log_body_is_set_correctly(): void
    {
        $watcher = $this->createLogWatcher();

        $log = new MessageLogged('info', 'Test log message body', []);

        $watcher->recordLog($log);

        $logRecord = $this->storage[0];
        $this->assertSame('Test log message body', $logRecord->getBody());
    }

    private function createLogWatcher(): LogWatcher
    {
        $instrumentation = new CachedInstrumentation(
            'test.log.watcher',
            null,
            'https://opentelemetry.io/schemas/1.32.0',
        );

        return new LogWatcher($instrumentation);
    }
}
