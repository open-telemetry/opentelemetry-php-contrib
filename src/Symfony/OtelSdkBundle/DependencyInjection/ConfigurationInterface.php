<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface as BaseConfigurationInterface;

interface ConfigurationInterface extends BaseConfigurationInterface, LoggerAwareInterface
{
    // PUBLIC CONSTANTS
    public const ROOT_KEY = 'otel_sdk';
    public const CUSTOM_TYPE = 'custom';
    public const DEFAULT_TYPE = 'default';
    public const CLASS_NODE = 'class';
    public const ID_NODE = 'id';
    public const RESOURCE_NODE = 'resource';
    public const LIMITS_NODE = 'limits';
    public const LIMITS_COUNT_DEFAULT = 128;
    public const ATTRIBUTES_NODE = 'attributes';
    public const SERVICE_NAME_ATTR = 'service.name';
    public const SERVICE_NAME_NODE = 'service_name';
    public const REQUIRED_SOURCE_ATTRS = [
        self::SERVICE_NAME_ATTR,
    ];
    public const TRACE_NODE = 'trace';
    public const SAMPLER_NODE = 'sampler';
    public const ROOT_NODE = 'root';
    public const REMOTE_NODE = 'remote';
    public const LOCAL_NODE = 'local';
    public const SAMPLED_NODE = 'sampled';
    public const NOT_SAMPLED_NODE = 'not_sampled';
    public const ALWAYS_ON_SAMPLER = 'always_on';
    public const ALWAYS_OFF_SAMPLER = 'always_off';
    public const TRACE_ID_RATIO_SAMPLER = 'trace_id_ratio_based';
    public const PARENT_BASED_SAMPLER = 'parent_based';
    public const SAMPLER_NODE_DEFAULT = self::ALWAYS_ON_SAMPLER;
    public const SAMPLER_NODE_VALUES = [
        self::ALWAYS_ON_SAMPLER,
        self::ALWAYS_OFF_SAMPLER,
        self::TRACE_ID_RATIO_SAMPLER,
    ];
    public const PROBABILITY = 'probability';
    public const PROBABILITY_DEFAULT = 1.0;
    public const SPAN_NODE = 'span';
    public const ATTR_COUNT_NODE = 'attribute_count';
    public const ATTR_VALUE_LENGTH_NODE = 'attribute_value_length';
    public const EVENT_COUNT_NODE = 'event_count';
    public const LINK_COUNT_NODE = 'link_count';
    public const ATTRS_EVENT_NODE = 'attribute_per_event';
    public const ATTRS_LINK_NODE = 'attribute_per_link';
    public const SPAN_LIMIT_ATTRS = [
        self::ATTR_COUNT_NODE,
        self::ATTR_VALUE_LENGTH_NODE,
        self::EVENT_COUNT_NODE,
        self::LINK_COUNT_NODE,
        self::ATTRS_EVENT_NODE,
        self::ATTRS_LINK_NODE,
    ];
    public const PROCESSORS_NODE = 'processors';
    public const SIMPLE_PROCESSOR = 'simple';
    public const BATCH_PROCESSOR = 'batch';
    public const NOOP_PROCESSOR = 'noop';
    public const MULTI_PROCESSOR = 'multi';
    public const PROCESSOR_DEFAULT = self::BATCH_PROCESSOR;
    public const PROCESSOR_NODE_VALUES = [
        self::SIMPLE_PROCESSOR,
        self::BATCH_PROCESSOR,
        self::NOOP_PROCESSOR,
    ];
    public const EXPORTERS_NODE = 'exporters';
    public const PROCESSOR_NODE = 'processor';
    public const DSN_NODE = 'dsn';
    public const TYPE_NODE = 'type';
    public const URL_NODE = 'url';
    public const OPTIONS_NODE = 'options';
    public const NAME_KEY = 'name';
    public const ENV_TYPE = 'env';
    public const JAEGER_EXPORTER = 'jaeger';
    public const ZIPKIN_EXPORTER = 'zipkin';
    public const NEWRELIC_EXPORTER = 'newrelic';
    public const OTLP_HTTP_EXPORTER = 'otlphttp';
    public const OTLP_GRPC_EXPORTER = 'otlpgrpc';
    public const ZIPKIN_TO_NEWRELIC_EXPORTER = 'zipkintonewrelic';
    public const EXPORTERS_NODE_VALUES = [
        self::JAEGER_EXPORTER,
        self::ZIPKIN_EXPORTER,
        self::NEWRELIC_EXPORTER,
        self::OTLP_HTTP_EXPORTER,
        self::OTLP_GRPC_EXPORTER,
        self::ZIPKIN_TO_NEWRELIC_EXPORTER,
    ];

    public function getConfigTreeBuilder(): TreeBuilder;

    public function getLogger(): LoggerInterface;

    public function setDebug(bool $debug): void;

    public function isDebug(): bool;
}
