<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Azure\Unit\Vm;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use OpenTelemetry\Azure\Vm\Detector;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SemConv\ResourceAttributes;
use PHPUnit\Framework\TestCase;

class DetectorTest extends TestCase
{
    public function test_valid_vm_attributes()
    {
        $body = $this->getResponseBodyFor('response.json');
        $metadata = json_decode($body, true);
        $mockGuzzle = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $body),
        ]);
        $handlerStack = HandlerStack::create($mockGuzzle);
        $client = new Client(['handler' => $handlerStack]);
        $requestFactory = new HttpFactory();
        $detector = new Detector($client, $requestFactory);

        $expected = [
            'azure.vm.scaleset.name' => $metadata['compute']['vmScaleSetName'],
            'azure.vm.sku' => $metadata['compute']['sku'],
            ResourceAttributes::CLOUD_PLATFORM => Detector::CLOUD_PLATFORM,
            ResourceAttributes::CLOUD_PROVIDER => Detector::CLOUD_PROVIDER,
            ResourceAttributes::CLOUD_REGION => $metadata['compute']['location'],
            ResourceAttributes::CLOUD_RESOURCE_ID => $metadata['compute']['resourceId'],
            ResourceAttributes::HOST_ID => $metadata['compute']['vmId'],
            ResourceAttributes::HOST_NAME => $metadata['compute']['name'],
            ResourceAttributes::HOST_TYPE => $metadata['compute']['vmSize'],
            ResourceAttributes::OS_TYPE => $metadata['compute']['osType'],
            ResourceAttributes::OS_VERSION => $metadata['compute']['version'],
            ResourceAttributes::SERVICE_INSTANCE_ID => $metadata['compute']['vmId'],
        ];

        $this->assertEquals(
            Attributes::create($expected),
            $detector->getResource()->getAttributes()
        );
    }

    private function getResponseBodyFor($filename)
    {
        return file_get_contents(dirname(__FILE__) . DIRECTORY_SEPARATOR . $filename);
    }
}
