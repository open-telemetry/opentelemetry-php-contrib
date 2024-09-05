<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue;

use Illuminate\Queue\BeanstalkdQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\SqsQueue;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue\ContextualAttributesBuilders\BeanstalkdQueueAttributes;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue\ContextualAttributesBuilders\RedisQueueAttributes;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Queue\ContextualAttributesBuilders\SqsQueueAttributes;

class AttributesBuilderRegister
{
    /**
     * @var ContextualAttributesBuilder[]
     */
    private static array $contextualAttributesBuilders = [];

    public static function registerContextualAttributesBuilder(ContextualAttributesBuilder $instance): void
    {
        self::$contextualAttributesBuilders[$instance::class] ??= $instance;
    }

    public static function clean(): void
    {
        self::$contextualAttributesBuilders = [];
    }

    /**
     * @return ContextualAttributesBuilder[]
     */
    public static function getBuilders(): array
    {
        self::ensureDefaultBuilderIsRegistered();

        return self::$contextualAttributesBuilders;
    }

    private static function ensureDefaultBuilderIsRegistered(): void
    {
        self::$contextualAttributesBuilders[BeanstalkdQueue::class] ??= new BeanstalkdQueueAttributes();
        self::$contextualAttributesBuilders[RedisQueue::class] ??= new RedisQueueAttributes();
        self::$contextualAttributesBuilders[SqsQueue::class] ??= new SqsQueueAttributes();
    }
}
