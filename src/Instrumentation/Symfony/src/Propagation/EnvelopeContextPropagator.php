<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony\Propagation;

use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\SerializerStamp;

/**
 * Propagator for handling OpenTelemetry context in Symfony Messenger envelopes.
 * This class handles both injection and extraction of context through SerializerStamp.
 */
final class EnvelopeContextPropagator implements PropagationGetterInterface, PropagationSetterInterface
{
    private const CONTEXT_KEY = 'otel_context';

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @param array<string, mixed> $carrier
     * @return array<int, string>
     */
    public function keys($carrier): array
    {
        return array_keys($carrier);
    }

    /**
     * @param array<string, mixed> $carrier
     */
    public function get($carrier, string $key): ?string
    {
        return $carrier[$key] ?? null;
    }

    /**
     * @param array<string, mixed> $carrier
     */
    public function set(&$carrier, string $key, string $value): void
    {
        $carrier[$key] = $value;
    }

    /**
     * Injects OpenTelemetry context into a Symfony Messenger envelope.
     */
    public function injectContextIntoEnvelope(Envelope $envelope, array $context): Envelope
    {
        if (empty($context)) {
            return $envelope;
        }

        $serializerContext = [
            self::CONTEXT_KEY => $context,
        ];

        return $envelope->with(new SerializerStamp($serializerContext));
    }

    /**
     * Extracts OpenTelemetry context from a Symfony Messenger envelope.
     */
    public function extractContextFromEnvelope(Envelope $envelope): ?array
    {
        $serializerStamps = $envelope->all(SerializerStamp::class);
        foreach ($serializerStamps as $serializerStamp) {
            /** @var SerializerStamp $serializerStamp */
            $context = $serializerStamp->getContext();
            if (isset($context[self::CONTEXT_KEY])) {
                return $context[self::CONTEXT_KEY];
            }
        }

        return null;
    }
} 