<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Unit\Watchers;

use ArrayObject;
use Exception;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Log\LogManager;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\LogWatcher;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SemConv\Attributes\ExceptionAttributes;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Stringable;

class LogWatcherTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;

    protected function setUp(): void
    {
        putenv(LogWatcher::OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN);

        $this->storage = new ArrayObject();
        $loggerProvider = new LoggerProvider(
            new SimpleLogRecordProcessor(
                new InMemoryExporter($this->storage),
            ),
            new InstrumentationScopeFactory(Attributes::factory()),
        );

        $this->scope = Configurator::create()
            ->withLoggerProvider($loggerProvider)
            ->activate();
    }

    protected function tearDown(): void
    {
        $this->scope->detach();
        putenv(LogWatcher::OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN);
    }

    private function createWatcher(): LogWatcher
    {
        $watcher = new LogWatcher(new CachedInstrumentation('io.opentelemetry.contrib.php.laravel'));

        // Inject a mock LogManager that passes all log levels through.
        // getLogger() is forwarded via __call on the real class, so addMethods() is required.
        $mockLogManager = $this->getMockBuilder(LogManager::class)
            ->disableOriginalConstructor()
            ->addMethods(['getLogger'])
            ->getMock();
        $mockLogManager->method('getLogger')->willReturn(new stdClass());

        $prop = new ReflectionProperty(LogWatcher::class, 'logger');
        $prop->setAccessible(true);
        $prop->setValue($watcher, $mockLogManager);

        return $watcher;
    }

    private function emit(LogWatcher $watcher, string $message, array $context = [], string $level = 'info'): void
    {
        $watcher->recordLog(new MessageLogged($level, $message, $context));
    }

    public function test_default_stores_context_as_structured_array(): void
    {
        $watcher = $this->createWatcher();
        $this->emit($watcher, 'hello', ['user_id' => 42, 'action' => 'login']);

        $record = $this->storage[0];
        $this->assertSame('hello', $record->getBody());
        $this->assertSame(['user_id' => 42, 'action' => 'login'], $record->getAttributes()->get('context'));
    }

    public function test_default_preserves_nested_arrays_in_context(): void
    {
        $watcher = $this->createWatcher();
        $this->emit($watcher, 'request', ['http' => ['method' => 'GET', 'path' => '/users'], 'user_id' => '123']);

        $record = $this->storage[0];
        $this->assertSame(
            ['http' => ['method' => 'GET', 'path' => '/users'], 'user_id' => '123'],
            $record->getAttributes()->get('context'),
        );
    }

    public function test_flatten_spreads_context_as_individual_attributes(): void
    {
        putenv(LogWatcher::OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN . '=true');
        $watcher = $this->createWatcher();
        $this->emit($watcher, 'login', ['user_id' => '123', 'action' => 'login']);

        $record = $this->storage[0];
        $attrs = $record->getAttributes()->toArray();
        $this->assertArrayNotHasKey('context', $attrs);
        $this->assertSame('123', $attrs['user_id']);
        $this->assertSame('login', $attrs['action']);
    }

    public function test_flatten_uses_dot_notation_for_nested_arrays(): void
    {
        putenv(LogWatcher::OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN . '=true');
        $watcher = $this->createWatcher();
        $this->emit($watcher, 'request', ['http' => ['method' => 'GET', 'path' => '/users', 'status' => 200]]);

        $record = $this->storage[0];
        $attrs = $record->getAttributes()->toArray();
        $this->assertArrayNotHasKey('context', $attrs);
        $this->assertSame('GET', $attrs['http.method']);
        $this->assertSame('/users', $attrs['http.path']);
        $this->assertSame(200, $attrs['http.status']);
    }

    public function test_exception_is_extracted_and_sets_exception_attributes(): void
    {
        $exception = new Exception('something broke');
        $watcher = $this->createWatcher();
        $this->emit($watcher, 'error logged', ['exception' => $exception], 'error');

        $record = $this->storage[0];
        $this->assertSame(Exception::class, $record->getAttributes()->get(ExceptionAttributes::EXCEPTION_TYPE));
        $this->assertSame('something broke', $record->getAttributes()->get(ExceptionAttributes::EXCEPTION_MESSAGE));
        $this->assertNotNull($record->getAttributes()->get(ExceptionAttributes::EXCEPTION_STACKTRACE));
        // exception key must not appear in context
        $context = $record->getAttributes()->get('context');
        $this->assertArrayNotHasKey('exception', (array) $context);
    }

    public function test_flatten_stringable_values_cast_to_string(): void
    {
        putenv(LogWatcher::OTEL_PHP_LARAVEL_LOG_ATTRIBUTES_FLATTEN . '=true');
        $watcher = $this->createWatcher();

        $stringable = new class() implements Stringable {
            public function __toString(): string
            {
                return 'stringable-value';
            }
        };

        $this->emit($watcher, 'msg', ['key' => $stringable]);

        $record = $this->storage[0];
        $this->assertSame('stringable-value', $record->getAttributes()->get('key'));
    }

    public function test_null_context_values_are_filtered_out(): void
    {
        $watcher = $this->createWatcher();
        $this->emit($watcher, 'msg', ['present' => 'yes', 'absent' => null]);

        $record = $this->storage[0];
        $context = $record->getAttributes()->get('context');
        $this->assertArrayHasKey('present', $context);
        $this->assertArrayNotHasKey('absent', $context);
    }

    public function test_flatten_disabled_by_default(): void
    {
        // No env var set; watcher must not flatten
        $watcher = $this->createWatcher();
        $this->emit($watcher, 'msg', ['a' => ['b' => 'c']]);

        $record = $this->storage[0];
        // 'a.b' must not exist; nested array must be under 'context'
        $this->assertNull($record->getAttributes()->get('a.b'));
        $this->assertIsArray($record->getAttributes()->get('context'));
    }
}
