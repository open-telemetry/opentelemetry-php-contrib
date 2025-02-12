<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Sampler\RuleBased\ComponentProvider;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Config\SDK\Configuration\ComponentProvider;
use OpenTelemetry\Config\SDK\Configuration\ComponentProviderRegistry;
use OpenTelemetry\Config\SDK\Configuration\Context;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule\SpanKindRule;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * @implements ComponentProvider<SamplingRule>
 */
final class SamplingRuleSpanKind implements ComponentProvider
{

    /**
     * @param array{
     *     kind: 'INTERNAL'|'CLIENT'|'SERVER'|'PRODUCER'|'CONSUMER',
     * } $properties
     */
    public function createPlugin(array $properties, Context $context): SamplingRule
    {
        return new SpanKindRule(
            match ($properties['kind']) {
                'INTERNAL' => SpanKind::KIND_INTERNAL,
                'CLIENT' => SpanKind::KIND_CLIENT,
                'SERVER' => SpanKind::KIND_SERVER,
                'PRODUCER' => SpanKind::KIND_PRODUCER,
                'CONSUMER' => SpanKind::KIND_CONSUMER,
            },
        );
    }

    public function getConfig(ComponentProviderRegistry $registry): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('span_kind');

        /** @psalm-suppress PossiblyNullReference */
        $node
            ->children()
                ->enumNode('kind')
                    ->isRequired()
                    ->values([
                        'INTERNAL',
                        'CLIENT',
                        'SERVER',
                        'PRODUCER',
                        'CONSUMER',
                    ])
                ->end()
            ->end()
        ;

        return $node;
    }
}
