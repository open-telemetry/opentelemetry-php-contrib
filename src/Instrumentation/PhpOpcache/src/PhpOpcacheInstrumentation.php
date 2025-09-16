<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PhpOpcache;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\Version;

class PhpOpcacheInstrumentation
{
    public const NAME = 'opcache';
    public const VERSION = '1.0.0';

    private static bool $registered = false;

    /**
     * Register the OPcache instrumentation
     *
     * @psalm-suppress UnusedMethod
     */
    public static function register(): void
    {
        if (!extension_loaded('opcache') || !extension_loaded('opentelemetry') || self::$registered) {
            return;
        }

        // Register our own shutdown function to capture OPcache metrics
        //    register_shutdown_function([self::class, 'captureMetricsOnShutdown']);
        register_shutdown_function('OpenTelemetry\Contrib\Instrumentation\PhpOpcache\PhpOpcacheInstrumentation::addOpcacheMetricsToRootSpan');

        self::$registered = true;
    }
    public static function addOpcacheMetricsToRootSpan(): void
    {
        // Get the current active span (root span)
        $span = Span::fromContext(Context::getCurrent());
        
        if (!$span->getContext()->isValid()) {
            return; // No active span, nothing to do
        }

        self::captureOpcacheMetrics($span);
    }

    private static function captureOpcacheMetrics(SpanInterface $span): void
    {
        if (!function_exists('opcache_get_status')) {
            $span->setAttribute('opcache.enabled', false);

            return;
        }

        $status = @opcache_get_status(false);
        if (!$status) {
            $span->setAttribute('opcache.available', false);

            return;
        }

        $span->setAttribute('opcache.enabled', true);
        $span->setAttribute('opcache.available', true);

        // Memory metrics
        if (isset($status['memory_usage'])) {
            $memory = $status['memory_usage'];
            self::addMemoryAttributes($span, $memory);
        }

        // Statistics metrics
        if (isset($status['opcache_statistics'])) {
            $stats = $status['opcache_statistics'];
            self::addStatisticsAttributes($span, $stats);
        }

        // Interned strings metrics
        if (isset($status['interned_strings_usage'])) {
            $interned = $status['interned_strings_usage'];
            self::addInternedStringsAttributes($span, $interned);
        }
    }

    private static function addMemoryAttributes(SpanInterface $span, array $memory): void
    {
        $span->setAttribute('opcache.memory.used_bytes', $memory['used_memory'] ?? 0);
        $span->setAttribute('opcache.memory.free_bytes', $memory['free_memory'] ?? 0);
        $span->setAttribute('opcache.memory.wasted_bytes', $memory['wasted_memory'] ?? 0);
        
        $used = $memory['used_memory'] ?? 0;
        $free = $memory['free_memory'] ?? 0;
        $wasted = $memory['wasted_memory'] ?? 0;
        $total = (int) $used + (int) $free + (int) $wasted;
        
        if ($total > 0) {
            $span->setAttribute('opcache.memory.used_percentage', round(($used / $total) * 100, 2));
            $span->setAttribute('opcache.memory.wasted_percentage', round(($wasted / $total) * 100, 2));
        }
    }

    private static function addStatisticsAttributes(SpanInterface $span, array $stats): void
    {
        $span->setAttribute('opcache.scripts.cached', $stats['num_cached_scripts'] ?? 0);
        $span->setAttribute('opcache.hits.total', $stats['hits'] ?? 0);
        $span->setAttribute('opcache.misses.total', $stats['misses'] ?? 0);
        
        $hits = (int) ($stats['hits'] ?? 0);
        $misses = (int) ($stats['misses'] ?? 0);
        $total = $hits + $misses;
        
        if ($total > 0) {
            $span->setAttribute('opcache.hit_rate.percentage', round(($hits / $total) * 100, 2));
        }
        
        // Restart metrics
        $span->setAttribute('opcache.restarts.oom', $stats['oom_restarts'] ?? 0);
        $span->setAttribute('opcache.restarts.hash', $stats['hash_restarts'] ?? 0);
        $span->setAttribute('opcache.restarts.manual', $stats['manual_restarts'] ?? 0);
        
        // Cache key metrics
        $span->setAttribute('opcache.keys.cached', $stats['num_cached_keys'] ?? 0);
        $span->setAttribute('opcache.keys.max_cached', $stats['max_cached_keys'] ?? 0);
    }

    private static function addInternedStringsAttributes(SpanInterface $span, array $interned): void
    {
        $span->setAttribute('opcache.interned_strings.buffer_size', $interned['buffer_size'] ?? 0);
        $span->setAttribute('opcache.interned_strings.used_memory', $interned['used_memory'] ?? 0);
        $span->setAttribute('opcache.interned_strings.free_memory', $interned['free_memory'] ?? 0);
        $span->setAttribute('opcache.interned_strings.strings_count', $interned['number_of_strings'] ?? 0);
        
        $used = $interned['used_memory'] ?? 0;
        $size = $interned['buffer_size'] ?? 0;
        
        if ($size > 0) {
            $span->setAttribute('opcache.interned_strings.usage_percentage', round(($used / $size) * 100, 2));
        }
    }
    /*public const NAME = 'php-opcache';
        public static function register(): void
        {
            $instrumentation = new CachedInstrumentation(
                'io.opentelemetry.contrib.php.opcache',
                null,
                Version::VERSION_1_36_0->url(),
        );
        // Register our own shutdown function to capture OPcache metrics
            register_shutdown_function([self::class, 'captureMetricsOnShutdown']);

        }
            // Hook into the shutdown process to capture OPcache metrics
            hook(
                null, // class name (null for global functions)
                'register_shutdown_function',
                pre: function (object|null $object, array $params) use ($instrumentation) {
                    // Store the current context to use in post hook
                    return [$instrumentation, Context::getCurrent()];
                },
                post: function (object|null $object, array $params, mixed $return, ?\Throwable $exception, array $hookData) {
                    list($instrumentation, $context) = $hookData;

                    // Create a span for OPcache metrics
                    $span = $instrumentation->tracer()->spanBuilder('opcache.metrics')
                        ->setSpanKind(SpanKind::KIND_INTERNAL)
                        ->setParent($context)
                        ->startSpan();

                    $scope = $span->activate();

                    try {
                        self::captureOpcacheMetrics($span);
                    } finally {
                        $span->end();
                        $scope->detach();
                    }
                }
        );*/
    /* public static function captureMetricsOnShutdown(): void
        {
            if (!self::$instrumentation) {
                return;
            }

            // Only create span if there's an active trace
            $parentContext = Context::getCurrent();
            $parentSpan = Span::fromContext($parentContext);

            if (!$parentSpan->getContext()->isValid()) {
                return;
            }

            $span = self::$instrumentation->tracer()->spanBuilder('opcache.metrics')
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->setParent($parentContext)
                ->startSpan();

            $scope = $span->activate();

            try {
                self::captureOpcacheMetrics($span);
            } finally {
                $span->end();
                $scope->detach();
            }
        }

        private static function captureOpcacheMetrics(Span $span): void
        {
            if (!function_exists('opcache_get_status')) {
                $span->setAttribute('opcache.enabled', false);
                return;
            }

            $status = @opcache_get_status(false);
            if (!$status) {
                $span->setAttribute('opcache.available', false);
                return;
            }

            $span->setAttribute('opcache.enabled', true);
            $span->setAttribute('opcache.available', true);

            // Memory metrics
            if (isset($status['memory_usage'])) {
                $memory = $status['memory_usage'];
                self::addMemoryMetrics($span, $memory);
            }

            // Statistics metrics
            if (isset($status['opcache_statistics'])) {
                $stats = $status['opcache_statistics'];
                self::addStatisticsMetrics($span, $stats);
            }

            // Interned strings metrics
            if (isset($status['interned_strings_usage'])) {
                $interned = $status['interned_strings_usage'];
                self::addInternedStringsMetrics($span, $interned);
            }
        }

        private static function addMemoryMetrics(Span $span, array $memory): void
        {
            $span->setAttribute('opcache.memory.used_bytes', $memory['used_memory'] ?? 0);
            $span->setAttribute('opcache.memory.free_bytes', $memory['free_memory'] ?? 0);
            $span->setAttribute('opcache.memory.wasted_bytes', $memory['wasted_memory'] ?? 0);

            $used = $memory['used_memory'] ?? 0;
            $free = $memory['free_memory'] ?? 0;
            $wasted = $memory['wasted_memory'] ?? 0;
            $total = $used + $free + $wasted;

            if ($total > 0) {
                $span->setAttribute('opcache.memory.used_percentage', round(($used / $total) * 100, 2));
                $span->setAttribute('opcache.memory.wasted_percentage', round(($wasted / $total) * 100, 2));
            }
        }

        private static function addStatisticsMetrics(Span $span, array $stats): void
        {
            $span->setAttribute('opcache.scripts.cached', $stats['num_cached_scripts'] ?? 0);
            $span->setAttribute('opcache.hits.total', $stats['hits'] ?? 0);
            $span->setAttribute('opcache.misses.total', $stats['misses'] ?? 0);

            $hits = $stats['hits'] ?? 0;
            $misses = $stats['misses'] ?? 0;
            $total = $hits + $misses;

            if ($total > 0) {
                $span->setAttribute('opcache.hit_rate.percentage', round(($hits / $total) * 100, 2));
            }

            // Restart metrics
            $span->setAttribute('opcache.restarts.oom', $stats['oom_restarts'] ?? 0);
            $span->setAttribute('opcache.restarts.hash', $stats['hash_restarts'] ?? 0);
            $span->setAttribute('opcache.restarts.manual', $stats['manual_restarts'] ?? 0);
        }

        private static function addInternedStringsMetrics(Span $span, array $interned): void
        {
            $span->setAttribute('opcache.interned_strings.buffer_size', $interned['buffer_size'] ?? 0);
            $span->setAttribute('opcache.interned_strings.used_memory', $interned['used_memory'] ?? 0);
            $span->setAttribute('opcache.interned_strings.strings_count', $interned['number_of_strings'] ?? 0);
        }
        /*
         *
        private static function captureOpcacheMetrics(Span $span): void
        {
            if (!function_exists('opcache_get_status')) {
                $span->setAttribute('opcache.enabled', false);

                return;
            }

            $status = @opcache_get_status(false);
            if (!$status) {
                $span->setAttribute('opcache.available', false);

                return;
            }

            $span->setAttribute('opcache.enabled', true);
            $span->setAttribute('opcache.available', true);

            // Memory metrics
            if (isset($status['memory_usage'])) {
                $memory = $status['memory_usage'];
                $span->setAttribute('opcache.memory.used_bytes', $memory['used_memory'] ?? 0);
                $span->setAttribute('opcache.memory.free_bytes', $memory['free_memory'] ?? 0);
                $span->setAttribute('opcache.memory.wasted_bytes', $memory['wasted_memory'] ?? 0);

                $used = $memory['used_memory'] ?? 0;
                $free = $memory['free_memory'] ?? 0;
                $wasted = $memory['wasted_memory'] ?? 0;
                $total = $used + $free + $wasted;

                if ($total > 0) {
                    $span->setAttribute('opcache.memory.used_percentage', round(($used / $total) * 100, 2));
                    $span->setAttribute('opcache.memory.wasted_percentage', round(($wasted / $total) * 100, 2));
                }
            }

            // Statistics metrics
            if (isset($status['opcache_statistics'])) {
                $stats = $status['opcache_statistics'];
                $span->setAttribute('opcache.scripts.cached', $stats['num_cached_scripts'] ?? 0);
                $span->setAttribute('opcache.hits.total', $stats['hits'] ?? 0);
                $span->setAttribute('opcache.misses.total', $stats['misses'] ?? 0);

                $hits = $stats['hits'] ?? 0;
                $misses = $stats['misses'] ?? 0;
                $total = $hits + $misses;

                if ($total > 0) {
                    $span->setAttribute('opcache.hit_rate.percentage', round(($hits / $total) * 100, 2));
                }

                // Restart metrics
                $span->setAttribute('opcache.restarts.oom', $stats['oom_restarts'] ?? 0);
                $span->setAttribute('opcache.restarts.hash', $stats['hash_restarts'] ?? 0);
                $span->setAttribute('opcache.restarts.manual', $stats['manual_restarts'] ?? 0);
            }

            // Interned strings metrics
            if (isset($status['interned_strings_usage'])) {
                $interned = $status['interned_strings_usage'];
                $span->setAttribute('opcache.interned_strings.buffer_size', $interned['buffer_size'] ?? 0);
                $span->setAttribute('opcache.interned_strings.used_memory', $interned['used_memory'] ?? 0);
                $span->setAttribute('opcache.interned_strings.strings_count', $interned['number_of_strings'] ?? 0);
            }
        }*/
}
