<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\Fixtures;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Minimal decorator forwarding every request to the wrapped client, optionally
 * issuing another request first (as an authentication decorator would).
 */
final class ForwardingHttpClient implements HttpClientInterface
{
    /** @var (callable(HttpClientInterface): void)|null */
    private $beforeRequest;

    public function __construct(
        private HttpClientInterface $client,
        ?callable $beforeRequest = null,
    ) {
        $this->beforeRequest = $beforeRequest;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if (null !== $this->beforeRequest) {
            ($this->beforeRequest)($this->client);
        }

        return $this->client->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }
}
