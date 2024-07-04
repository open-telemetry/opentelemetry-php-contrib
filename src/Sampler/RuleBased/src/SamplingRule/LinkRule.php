<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Sampler\RuleBased\SamplingRule;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use function sprintf;
use function var_export;

/**
 * Checks whether at least one link matches sampled and remote.
 */
final class LinkRule implements SamplingRule
{

    public function __construct(
        private readonly bool $sampled,
        private readonly ?bool $remote = null,
    ) {
    }

    public function matches(
        ContextInterface $context,
        string $traceId,
        string $spanName,
        int $spanKind,
        AttributesInterface $attributes,
        array $links,
    ): bool {
        foreach ($links as $link) {
            // @var \OpenTelemetry\SDK\Trace\LinkInterface $link
            if ($link->getSpanContext()->isSampled() !== $this->sampled) {
                continue;
            }
            if ($this->remote !== null && $link->getSpanContext()->isRemote() !== $this->remote) {
                continue;
            }

            return true;
        }

        return false;
    }

    public function __toString(): string
    {
        return sprintf('Link{sampled=%s,remote=%s}', var_export($this->sampled, true), var_export($this->remote, true));
    }
}
