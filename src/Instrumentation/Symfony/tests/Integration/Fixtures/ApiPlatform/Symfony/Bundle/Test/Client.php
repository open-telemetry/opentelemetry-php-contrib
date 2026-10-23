<?php

declare(strict_types=1);

namespace ApiPlatform\Symfony\Bundle\Test;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Stand-in for the ApiPlatform test client, which is listed in
 * HttpClientInstrumentation::SYNCHRONOUS_CLIENTS and records the options it
 * receives so tests can check what the instrumentation injected.
 */
final class Client implements HttpClientInterface
{
    public array $options = [];

    /** @psalm-suppress PossiblyUnusedReturnValue */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->options = $options;

        return new MockResponse();
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new \LogicException('Not implemented');
    }

    public function withOptions(array $options): static
    {
        return clone $this;
    }
}
