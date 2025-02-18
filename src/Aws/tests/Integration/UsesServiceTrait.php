<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Aws\Integration;

use Aws\Api\Service;
use Aws\AwsClientInterface;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sdk;
use GuzzleHttp\Promise\PromiseInterface;

trait UsesServiceTrait
{
    private MockHandler $_mock_handler;

    /**
     * Creates an instance of the AWS SDK for a test
     */
    private function getTestSdk(array $args = []): Sdk
    {
        return new Sdk($args + [
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => false,
            'retries' => 0,
        ]);
    }

    /**
     * Creates an instance of a service client for a test
     */
    private function getTestClient(string $service, array $args = []): AwsClientInterface
    {
        $this->_mock_handler = new MockHandler([]);

        return $this->getTestSdk($args)->createClient($service);
    }

    /**
     * Queues up mock Result objects for a client
     *
     * @param Result[]|array[] $results
     */
    private function addMockResults(
        AwsClientInterface $client,
        array $results,
        ?callable $onFulfilled = null,
        ?callable $onRejected = null
    ): AwsClientInterface {
        foreach ($results as &$res) {
            if (is_array($res)) {
                $res = new Result($res);
            }
        }

        $this->_mock_handler = new MockHandler($results, $onFulfilled, $onRejected);
        $client->getHandlerList()->setHandler($this->_mock_handler);

        return $client;
    }

    private function mockQueueEmpty(): bool
    {
        return 0 === count($this->_mock_handler);
    }

    /**
     * Creates a mock CommandException with a given error code
     * @psalm-suppress MoreSpecificReturnType
     */
    private function createMockAwsException(
        ?string $code = null,
        ?string $type = null,
        ?string $message = null
    ): AwsException {
        $code = $code ?: 'ERROR';
        $type = $type ?: AwsException::class;

        $client = $this->getMockBuilder(AwsClientInterface::class)
            ->setMethods(['getApi'])
            ->getMockForAbstractClass();

        /** @psalm-suppress InternalMethod */
        $client->expects($this->any())
            ->method('getApi')
            ->willReturn(new Service(
                [
                    'metadata' => [
                        'endpointPrefix' => 'foo',
                        'apiVersion' => 'version',
                    ],
                ],
                function () {
                    return [];
                }
            ));

        return new $type(
            $message ?: 'Test error',
            $this->getMockBuilder(CommandInterface::class)->getMock(),
            [
                'message' => $message ?: 'Test error',
                'code'    => $code,
            ]
        );
    }

    /**
     * Verifies an operation alias returns the expected types
     */
    private function verifyOperationAlias(
        AwsClientInterface $client,
        string $operation,
        array $params
    ) {
        $this->addMockResults($client, [new Result()]);
        $output = $client->{$operation}($params);
        if (substr($operation, -5) === 'Async') {
            $this->assertFalse($this->mockQueueEmpty());
            $this->assertInstanceOf(PromiseInterface::class, $output);
            $output = $output->wait();
            $this->assertTrue($this->mockQueueEmpty());
        }
        $this->assertInstanceOf(Result::class, $output);
        $this->assertTrue($this->mockQueueEmpty());
    }
}
