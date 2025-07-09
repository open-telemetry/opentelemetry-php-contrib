<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use OpenTelemetry\Contrib\Sampler\Xray\AWSXRaySamplerClient;
use OpenTelemetry\Contrib\Sampler\Xray\SamplingRule;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Response;

final class AWSXRaySamplerClientTest extends TestCase
{
    public function testGetSamplingRulesParsesResponse(): void
    {
        $json = <<<'JSON'
{
    "NextToken": null,
    "SamplingRuleRecords": [
        {
            "CreatedAt": 1.676038494E9,
            "ModifiedAt": 1.676038494E9,
            "SamplingRule": {
                "Attributes": {
                    "foo": "bar",
                    "abc": "1234"
                },
                "FixedRate": 0.05,
                "HTTPMethod": "*",
                "Host": "*",
                "Priority": 10000,
                "ReservoirSize": 100,
                "ResourceARN": "*",
                "RuleARN": "arn:aws:xray:us-east-1:999999999999:sampling-rule/Default",
                "RuleName": "Default",
                "ServiceName": "*",
                "ServiceType": "*",
                "URLPath": "*",
                "Version": 1
            }
        },
        {
            "CreatedAt": 1.67799933E9,
            "ModifiedAt": 1.67799933E9,
            "SamplingRule": {
                "Attributes": {
                    "abc": "1234"
                },
                "FixedRate": 0.11,
                "HTTPMethod": "*",
                "Host": "*",
                "Priority": 20,
                "ReservoirSize": 1,
                "ResourceARN": "*",
                "RuleARN": "arn:aws:xray:us-east-1:999999999999:sampling-rule/test",
                "RuleName": "test",
                "ServiceName": "*",
                "ServiceType": "*",
                "URLPath": "*",
                "Version": 1
            }
        }
    ]
}
JSON;

        // Mock the Guzzle HTTP client
        $mockHttp = $this->createMock(HttpClient::class);
        $response = new Response(200, ['Content-Type' => 'application/json'], $json);
        $mockHttp->expects($this->once())
                 ->method('post')
                 ->with('GetSamplingRules')
                 ->willReturn($response);

        // Instantiate and inject the mock client
        $client = new AWSXRaySamplerClient('https://dummy');
        $ref = new ReflectionClass($client);
        $prop = $ref->getProperty('httpClient');
        $prop->setAccessible(true);
        $prop->setValue($client, $mockHttp);

        // Exercise getSamplingRules()
        $rules = $client->getSamplingRules();

        // Assertions
        $this->assertCount(2, $rules);
        $this->assertInstanceOf(SamplingRule::class, $rules[0]);
        $this->assertEquals('Default', $rules[0]->RuleName);
        $this->assertEquals(10000, $rules[0]->Priority);
        $this->assertEquals(0.05, $rules[0]->FixedRate);
        $this->assertEquals(100, $rules[0]->ReservoirSize);
        $this->assertEquals(['foo' => 'bar', 'abc' => '1234'], $rules[0]->Attributes);

        $this->assertEquals('test', $rules[1]->RuleName);
        $this->assertEquals(20, $rules[1]->Priority);
        $this->assertEquals(0.11, $rules[1]->FixedRate);
        $this->assertEquals(1, $rules[1]->ReservoirSize);
        $this->assertEquals(['abc' => '1234'], $rules[1]->Attributes);
    }

    public function testGetSamplingTargetsParsesResponse(): void
    {
        $json = <<<'JSON'
{
    "LastRuleModification": 1707551387.0,
    "SamplingTargetDocuments": [
        {
            "FixedRate": 0.10,
            "Interval": 10,
            "ReservoirQuota": 30,
            "ReservoirQuotaTTL": 1707764006.0,
            "RuleName": "test"
        },
        {
            "FixedRate": 0.05,
            "Interval": 10,
            "ReservoirQuota": 0,
            "ReservoirQuotaTTL": 1707764006.0,
            "RuleName": "Default"
        }
    ],
    "UnprocessedStatistics": []
}
JSON;

        $stats = [
            (object)[
                'ClientID'     => 'cid',
                'RuleName'     => 'test',
                'RequestCount' => 1,
                'SampleCount'  => 1,
                'BorrowCount'  => 0,
                'Timestamp'    => 1234567890.0,
            ]
        ];

        // Mock the Guzzle HTTP client
        $mockHttp = $this->createMock(HttpClient::class);
        $response = new Response(200, ['Content-Type' => 'application/json'], $json);
        $mockHttp->expects($this->once())
                 ->method('post')
                 ->with(
                     'SamplingTargets',
                     $this->callback(fn($opts) => 
                         isset($opts['json']['SamplingStatisticsDocuments']) &&
                         count($opts['json']['SamplingStatisticsDocuments']) === 1
                     )
                 )
                 ->willReturn($response);

        // Instantiate and inject the mock client
        $client = new AWSXRaySamplerClient('https://dummy');
        $ref = new ReflectionClass($client);
        $prop = $ref->getProperty('httpClient');
        $prop->setAccessible(true);
        $prop->setValue($client, $mockHttp);

        // Exercise getSamplingTargets()
        $result = $client->getSamplingTargets($stats);

        // Assertions on the stdClass response
        $this->assertIsObject($result);
        $this->assertEquals(1707551387.0, $result->LastRuleModification);

        $docs = $result->SamplingTargetDocuments;
        $this->assertCount(2, $docs);

        $this->assertEquals('test',   $docs[0]->RuleName);
        $this->assertEquals(0.10,     $docs[0]->FixedRate);
        $this->assertEquals(30,       $docs[0]->ReservoirQuota);
        $this->assertEquals(1707764006.0, $docs[0]->ReservoirQuotaTTL);
        $this->assertEquals(10,       $docs[0]->Interval);

        $this->assertEquals('Default',$docs[1]->RuleName);
        $this->assertEquals(0.05,     $docs[1]->FixedRate);
        $this->assertEquals(0,        $docs[1]->ReservoirQuota);
    }
}
