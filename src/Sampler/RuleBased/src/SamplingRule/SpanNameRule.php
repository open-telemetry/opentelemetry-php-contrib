<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Contrib\Sampler\SamplingRule;

use Nevay\OTelSDK\Common\Attributes;
use Nevay\OTelSDK\Contrib\Sampler\SamplingRule;
use Nevay\OTelSDK\Trace\Span\Kind;
use OpenTelemetry\Context\ContextInterface;
use function preg_match;
use function sprintf;

final class SpanNameRule implements SamplingRule {

    public function __construct(
        private readonly string $pattern,
    ) {}

    public function matches(
        ContextInterface $context,
        string $traceId,
        string $spanName,
        Kind $spanKind,
        Attributes $attributes,
        array $links,
    ): bool {
        return (bool) preg_match($this->pattern, $spanName);
    }

    public function __toString(): string {
        return sprintf('SpanName{pattern=%s}', $this->pattern);
    }
}
