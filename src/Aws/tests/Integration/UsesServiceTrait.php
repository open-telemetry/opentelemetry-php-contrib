<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Aws\Integration;

use Aws\AwsClientInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sdk;

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
    ): void {
        foreach ($results as &$res) {
            if (is_array($res)) {
                $res = new Result($res);
            }
        }
        unset($res);

        $this->_mock_handler = new MockHandler($results, $onFulfilled, $onRejected);
        $client->getHandlerList()->setHandler($this->_mock_handler);
    }
}
