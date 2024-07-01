<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Contrib\Sampler;

use Nevay\OTelSDK\Common\Attributes;
use Nevay\OTelSDK\Trace\Sampler;
use Nevay\OTelSDK\Trace\SamplingResult;
use Nevay\OTelSDK\Trace\Span\Kind;
use OpenTelemetry\Context\ContextInterface;
use function sprintf;

/**
 * Records all spans to allow the usage of span processors that generate metrics from spans.
 */
final class AlwaysRecordingSampler implements Sampler {

    public function __construct(
        private readonly Sampler $sampler,
    ) {}

    public function shouldSample(
        ContextInterface $context,
        string $traceId,
        string $spanName,
        Kind $spanKind,
        Attributes $attributes,
        array $links,
    ): SamplingResult {
        $result = $this->sampler->shouldSample($context, $traceId, $spanName, $spanKind, $attributes, $links);
        if (!$result->shouldRecord()) {
            $result = new AlwaysRecordingSamplingResult($result);
        }

        return $result;
    }

    public function __toString(): string {
        return sprintf('AlwaysRecordingSampler{%s}', $this->sampler);
    }
}
