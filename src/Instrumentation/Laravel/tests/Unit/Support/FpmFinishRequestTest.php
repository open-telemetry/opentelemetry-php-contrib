<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Unit\Support;

use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Support\FpmFinishRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FpmFinishRequestTest extends TestCase
{
    protected function setUp(): void
    {
        $this->setEnabled(false);
        $this->setOctaneSignal(null);
        FpmFinishRequest::resetCache();
        Logging::reset();
    }

    protected function tearDown(): void
    {
        $this->setEnabled(false);
        $this->setOctaneSignal(null);
        FpmFinishRequest::resetCache();
        Logging::reset();
    }

    /**
     * @dataProvider disabledEnvProvider
     */
    public function test_is_not_enabled_by_default(?string $raw): void
    {
        if ($raw === null) {
            putenv(FpmFinishRequest::ENV_ENABLED);
            unset($_SERVER[FpmFinishRequest::ENV_ENABLED]);
        } else {
            putenv(FpmFinishRequest::ENV_ENABLED . '=' . $raw);
            $_SERVER[FpmFinishRequest::ENV_ENABLED] = $raw;
        }
        FpmFinishRequest::resetCache();

        $this->assertFalse(FpmFinishRequest::isEnabled());
    }

    /**
     * @dataProvider enabledEnvProvider
     */
    public function test_is_enabled_when_env_is_truthy(string $raw): void
    {
        putenv(FpmFinishRequest::ENV_ENABLED . '=' . $raw);
        $_SERVER[FpmFinishRequest::ENV_ENABLED] = $raw;
        FpmFinishRequest::resetCache();

        $this->assertTrue(FpmFinishRequest::isEnabled());
    }

    public function test_is_enabled_when_server_signal_is_scalar(): void
    {
        putenv(FpmFinishRequest::ENV_ENABLED);
        $_SERVER[FpmFinishRequest::ENV_ENABLED] = 1;
        FpmFinishRequest::resetCache();

        $this->assertTrue(FpmFinishRequest::isEnabled());
    }

    public function test_is_enabled_result_is_cached(): void
    {
        $this->setEnabled(true);
        $this->assertTrue(FpmFinishRequest::isEnabled());

        try {
            // Change env without resetting cache; cached value should be retained.
            putenv(FpmFinishRequest::ENV_ENABLED . '=false');
            $_SERVER[FpmFinishRequest::ENV_ENABLED] = 'false';

            $this->assertTrue(FpmFinishRequest::isEnabled());
        } finally {
            putenv(FpmFinishRequest::ENV_ENABLED);
            unset($_SERVER[FpmFinishRequest::ENV_ENABLED]);
        }
    }

    public function test_finish_request_is_called_when_enabled(): void
    {
        $this->setEnabled(true);

        $called = false;
        FpmFinishRequest::setFinishRequestCallback(static function () use (&$called): void {
            $called = true;
        });

        FpmFinishRequest::handle();

        $this->assertTrue($called, 'fastcgi_finish_request() should have been called when enabled');
    }

    public function test_finish_request_is_not_called_when_disabled(): void
    {
        $this->setEnabled(false);

        $called = false;
        FpmFinishRequest::setFinishRequestCallback(static function () use (&$called): void {
            $called = true;
        });

        FpmFinishRequest::handle();

        $this->assertFalse($called, 'fastcgi_finish_request() must not be called when disabled');
    }

    public function test_finish_request_is_not_called_when_octane_env_signal_is_set(): void
    {
        $this->setEnabled(true);
        $this->setOctaneSignal('1');

        $called = false;
        FpmFinishRequest::setFinishRequestCallback(static function () use (&$called): void {
            $called = true;
        });

        FpmFinishRequest::handle();

        $this->assertFalse($called, 'fastcgi_finish_request() must not be called in long-running runtime');
    }

    public function test_explicit_octane_false_allows_finish_request(): void
    {
        $this->setEnabled(true);
        $this->setOctaneSignal('false');

        $called = false;
        FpmFinishRequest::setFinishRequestCallback(static function () use (&$called): void {
            $called = true;
        });

        FpmFinishRequest::handle();

        $this->assertTrue($called, 'Explicit LARAVEL_OCTANE=false should allow fastcgi_finish_request()');
    }

    public function test_explicit_octane_zero_allows_finish_request(): void
    {
        $this->setEnabled(true);
        $this->setOctaneSignal('0');

        $called = false;
        FpmFinishRequest::setFinishRequestCallback(static function () use (&$called): void {
            $called = true;
        });

        FpmFinishRequest::handle();

        $this->assertTrue($called, 'LARAVEL_OCTANE=0 should allow fastcgi_finish_request()');
    }

    public function test_warning_is_logged_when_octane_guard_fires(): void
    {
        $this->setEnabled(true);
        $this->setOctaneSignal('1');

        $called = false;
        FpmFinishRequest::setFinishRequestCallback(static function () use (&$called): void {
            $called = true;
        });

        $logs = $this->collectWarnings(static fn () => FpmFinishRequest::handle());

        $this->assertWarningEntriesContain(
            $logs,
            FpmFinishRequest::WARNING_PREFIX . ' skipping fastcgi_finish_request() in long-running Laravel runtime'
        );

        $this->assertFalse($called, 'finishRequest() must not be called when Octane guard fires');
    }

    public function test_octane_warning_is_logged_only_once(): void
    {
        $this->setEnabled(true);
        $this->setOctaneSignal('1');

        FpmFinishRequest::setFinishRequestCallback(static function (): void {
        });

        $logs = $this->collectWarnings(static function (): void {
            FpmFinishRequest::handle();
            FpmFinishRequest::handle();
        });

        $warningCount = count(array_filter(
            $logs,
            static fn (array $entry): bool => str_contains($entry['message'], 'skipping fastcgi_finish_request()')
        ));

        $this->assertSame(1, $warningCount, 'Warning must be emitted exactly once per distinct message');
    }

    public function test_distinct_long_running_warnings_are_both_logged(): void
    {
        $this->setEnabled(true);

        FpmFinishRequest::setFinishRequestCallback(static function (): void {
        });

        $logs = $this->collectWarnings(function (): void {
            // Intentionally switch signals without cache reset to prove
            // distinct warning messages are deduplicated independently.
            $this->setOctaneSignal('roadrunner');
            FpmFinishRequest::handle();

            $this->setOctaneSignal('1');
            FpmFinishRequest::handle();
        });

        $this->assertWarningEntriesContain($logs, 'unrecognized LARAVEL_OCTANE value "roadrunner"');
        $this->assertWarningEntriesContain($logs, 'skipping fastcgi_finish_request() in long-running Laravel runtime');
    }

    public function test_warning_is_logged_when_octane_signal_is_invalid(): void
    {
        $this->setEnabled(true);
        $this->setOctaneSignal('roadrunner');

        $called = false;
        FpmFinishRequest::setFinishRequestCallback(static function () use (&$called): void {
            $called = true;
        });

        $logs = $this->collectWarnings(static fn () => FpmFinishRequest::handle());

        $this->assertWarningEntriesContain(
            $logs,
            FpmFinishRequest::WARNING_PREFIX . ' unrecognized LARAVEL_OCTANE value "roadrunner"; expected boolean-like value, treating as long-running runtime (fail-closed)'
        );
        $this->assertFalse($called, 'finishRequest() must not fire when LARAVEL_OCTANE is unrecognized');
    }

    public function test_handle_delegates_every_call(): void
    {
        $this->setEnabled(true);

        $callCount = 0;
        FpmFinishRequest::setFinishRequestCallback(static function () use (&$callCount): void {
            $callCount++;
        });

        FpmFinishRequest::handle();
        FpmFinishRequest::handle();

        $this->assertSame(2, $callCount, 'handle() should delegate every call; idempotency is the caller\'s responsibility');
    }

    public function test_warning_is_logged_when_finish_request_callback_throws(): void
    {
        $this->setEnabled(true);

        FpmFinishRequest::setFinishRequestCallback(static function (): void {
            throw new RuntimeException('callback exploded');
        });

        $logs = $this->collectWarnings(static fn () => FpmFinishRequest::handle());

        $this->assertWarningEntriesContain(
            $logs,
            FpmFinishRequest::WARNING_PREFIX . ' finish-request callback threw: callback exploded'
        );
    }

    public function test_reset_cache_clears_warning_dedup_state(): void
    {
        $this->setEnabled(true);
        $this->setOctaneSignal('1');

        FpmFinishRequest::setFinishRequestCallback(static function (): void {
        });

        $this->collectWarnings(static fn () => FpmFinishRequest::handle());
        FpmFinishRequest::resetCache();

        // Re-arm state explicitly after resetCache() cleared all internals.
        $this->setEnabled(true);
        FpmFinishRequest::setFinishRequestCallback(static function (): void {
        });

        $logs = $this->collectWarnings(static fn () => FpmFinishRequest::handle());
        $this->assertWarningEntriesContain($logs, 'skipping fastcgi_finish_request()');
    }

    /** @return array<string, array{0: ?string}> */
    public static function disabledEnvProvider(): array
    {
        return [
            'unset' => [null],
            'empty' => [''],
            'zero' => ['0'],
            'false' => ['false'],
            'no' => ['no'],
            'off' => ['off'],
            'invalid' => ['not-a-bool'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function enabledEnvProvider(): array
    {
        return [
            'true' => ['true'],
            'one' => ['1'],
            'yes' => ['yes'],
            'on' => ['on'],
        ];
    }

    private function setEnabled(bool $enabled): void
    {
        if ($enabled) {
            putenv(FpmFinishRequest::ENV_ENABLED . '=true');
            $_SERVER[FpmFinishRequest::ENV_ENABLED] = 'true';
        } else {
            putenv(FpmFinishRequest::ENV_ENABLED);
            unset($_SERVER[FpmFinishRequest::ENV_ENABLED]);
        }
    }

    private function setOctaneSignal(?string $value): void
    {
        if ($value === null) {
            putenv(FpmFinishRequest::ENV_LARAVEL_OCTANE);
            unset($_SERVER[FpmFinishRequest::ENV_LARAVEL_OCTANE]);

            return;
        }

        putenv(FpmFinishRequest::ENV_LARAVEL_OCTANE . '=' . $value);
        $_SERVER[FpmFinishRequest::ENV_LARAVEL_OCTANE] = $value;
    }

    /** @return list<array{level: string, message: string}> */
    private function collectWarnings(callable $callback): array
    {
        $logs = (object) ['entries' => []];
        Logging::setLogWriter(new class($logs) implements LogWriterInterface {
            public function __construct(private readonly object $logs)
            {
            }

            public function write($level, string $message, array $context): void
            {
                $this->logs->entries[] = ['level' => (string) $level, 'message' => $message];
            }
        });

        try {
            $callback();
        } finally {
            Logging::reset();
        }

        /** @var list<array{level: string, message: string}> $entries */
        $entries = $logs->entries;

        return $entries;
    }

    /** @param list<array{level: string, message: string}> $logs */
    private function assertWarningEntriesContain(array $logs, string $expectedSubstring): void
    {
        $found = false;
        foreach ($logs as $entry) {
            if (str_contains($entry['message'], $expectedSubstring)) {
                $found = true;

                break;
            }
        }

        $encoded = json_encode($logs);
        $this->assertTrue(
            $found,
            sprintf(
                'Expected a warning containing "%s", got: %s',
                $expectedSubstring,
                $encoded !== false ? $encoded : '(json_encode failed)'
            )
        );
    }
}
