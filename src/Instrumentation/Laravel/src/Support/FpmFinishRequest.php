<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Support;

use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;
use function filter_var;
use function function_exists;
use function getenv;
use function is_scalar;
use function method_exists;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use function sprintf;
use Throwable;

/**
 * Closes the FastCGI connection during Laravel Kernel::terminate() so the
 * FPM worker can flush telemetry in the background without blocking the
 * client or upstream proxy.
 *
 * Enable for PHP-FPM workloads only. Do not use with long-running runtimes
 * such as Laravel Octane (Swoole/RoadRunner).
 */
final class FpmFinishRequest
{
    use LogsMessagesTrait;

    public const ENV_ENABLED = 'OTEL_PHP_INSTRUMENTATION_LARAVEL_FPM_FINISH_REQUEST_ENABLED';
    public const ENV_LARAVEL_OCTANE = 'LARAVEL_OCTANE';
    public const WARNING_PREFIX = '[otel.laravel.fpm]';

    private static ?bool $enabled = null;
    /** @var array<string, true> */
    private static array $loggedWarnings = [];
    /** @var (callable(): void)|null */
    private static $finishRequestCallback = null;

    /** @psalm-suppress UnusedConstructor */
    private function __construct()
    {
    }

    public static function isEnabled(): bool
    {
        $enabled = self::$enabled;
        if ($enabled !== null) {
            return $enabled;
        }

        $raw = self::readEnv(self::ENV_ENABLED);
        $enabled = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        self::$enabled = $enabled;

        return $enabled;
    }

    /** @internal For testing only. */
    public static function resetCache(): void
    {
        self::$enabled = null;
        self::$loggedWarnings = [];
        self::$finishRequestCallback = null;
    }

    /**
     * Override the finish-request implementation. Pass null to restore the
     * default (fastcgi_finish_request() when available).
     *
     * @internal For testing only.
     * @param (callable(): void)|null $callback
     */
    public static function setFinishRequestCallback(?callable $callback): void
    {
        self::$finishRequestCallback = $callback;
    }

    public static function handle(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $warning = self::longRunningRuntimeWarning();
        if ($warning !== null) {
            // Deduplicate runtime-guard warnings only. Callback errors from
            // finishRequest() are intentionally not deduplicated because a
            // throwing callback indicates an active configuration defect.
            if (!isset(self::$loggedWarnings[$warning])) {
                self::logWarning($warning);
                self::$loggedWarnings[$warning] = true;
            }

            return;
        }

        self::finishRequest();
    }

    private static function finishRequest(): void
    {
        if (self::$finishRequestCallback !== null) {
            try {
                (self::$finishRequestCallback)();
            } catch (Throwable $throwable) {
                self::logWarning(sprintf(
                    '%s finish-request callback threw: %s',
                    self::WARNING_PREFIX,
                    $throwable->getMessage(),
                ));
            }

            return;
        }

        if (function_exists('fastcgi_finish_request')) {
            // The false-return path cannot be exercised in unit tests because
            // fastcgi_finish_request() is a C built-in and cannot be mocked.
            // The callback path (setFinishRequestCallback) is used for testing instead.
            // @codeCoverageIgnoreStart
            if (fastcgi_finish_request() === false) {
                self::logWarning(sprintf(
                    '%s fastcgi_finish_request() returned false; response may not have been flushed',
                    self::WARNING_PREFIX,
                ));
            }
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * Env signals are re-evaluated on each call. Container lookup remains a
     * lightweight fallback when no env signal is present.
     *
     * Warning dedup uses rendered message text as key; this includes the raw
     * env signal for invalid values and is therefore bounded by distinct values
     * seen over the process lifetime.
     *
     * @return string|null Warning message if running in a long-lived process, null if safe to proceed.
     */
    private static function longRunningRuntimeWarning(): ?string
    {
        $signal = self::readEnv(self::ENV_LARAVEL_OCTANE);
        if ($signal !== null) {
            $isEnabled = filter_var($signal, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isEnabled === true) {
                return self::longRunningRuntimeMessage();
            }

            if ($isEnabled === null) {
                return sprintf(
                    '%s unrecognized %s value "%s"; expected boolean-like value, treating as long-running runtime (fail-closed)',
                    self::WARNING_PREFIX,
                    self::ENV_LARAVEL_OCTANE,
                    $signal,
                );
            }
        }

        if (self::isLongRunningContainerRuntime()) {
            return self::longRunningRuntimeMessage();
        }

        return null;
    }

    private static function longRunningRuntimeMessage(): string
    {
        return sprintf(
            '%s skipping fastcgi_finish_request() in long-running Laravel runtime',
            self::WARNING_PREFIX,
        );
    }

    private static function isLongRunningContainerRuntime(): bool
    {
        if (!function_exists('app')) {
            return false;
        }

        // Container fallback is integration-oriented; unit tests primarily cover env-based guards.
        try {
            $app = app();
            if (!method_exists($app, 'bound')) {
                return false;
            }

            if ($app->bound('octane') || $app->bound('octane.client') || $app->bound('octane.worker')) {
                return true;
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    private static function readEnv(string $key): ?string
    {
        $server = $_SERVER[$key] ?? null;
        if ($server !== null && $server !== '') {
            // Web-server SAPIs may occasionally provide non-string scalar values.
            if (is_scalar($server)) {
                return (string) $server;
            }

            return null;
        }

        $env = getenv($key);

        return ($env === false || $env === '') ? null : $env;
    }
}
