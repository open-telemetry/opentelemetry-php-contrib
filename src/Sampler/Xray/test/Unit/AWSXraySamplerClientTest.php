<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use OpenTelemetry\Contrib\Sampler\Xray\AWSXRaySamplerClient;
use GuzzleHttp\Psr7\Response;
use OpenTelemetry\Contrib\Sampler\Xray\SamplingStatisticsDocument;
use OpenTelemetry\Contrib\Sampler\Xray\SamplingRule;

final class AWSXRaySamplerClientTest extends TestCase
{
    private string $rulesJson;
    private string $targetsJson;

    protected function setUp(): void
    {
        $dataDir = __DIR__ . '/data';
        $this->rulesJson  = file_get_contents($dataDir . '/sampling_rules.json');
        $this->targetsJson = file_get_contents($dataDir . '/sampling_targets.json');
    }

    public function testGetSamplingRules(): void
    {
        // 1) Mock Guzzle client to return our sample JSON
        $mockHttp = $this->createMock(\GuzzleHttp\Client::class);
        $mockHttp->expects($this->once())
            ->method('post')
            ->with('GetSamplingRules')
            ->willReturn(new Response(200, [], $this->rulesJson));

        // 2) Instantiate client and inject mock
        $client = new AWSXRaySamplerClient('https://xray');
        $ref    = new ReflectionClass($client);
        $prop   = $ref->getProperty('httpClient');
        $prop->setAccessible(true);
        $prop->setValue($client, $mockHttp);

        // 3) Call method under test
        $rules = $client->getSamplingRules();

        // 4) Assertions: two rules, correct mapping
        $this->assertCount(2, $rules);

        /** @var SamplingRule $r1 */
        $r1 = $rules[0];
        $this->assertSame('Default', $r1->RuleName);
        $this->assertSame(0.05, $r1->FixedRate);
        $this->assertSame(100, $r1->ReservoirSize);
        $this->assertEquals(['foo'=>'bar','abc'=>'1234'], $r1->Attributes);

        /** @var SamplingRule $r2 */
        $r2 = $rules[1];
        $this->assertSame('test', $r2->RuleName);
        $this->assertSame(0.11, $r2->FixedRate);
        $this->assertSame(1, $r2->ReservoirSize);
    }

    public function testGetSamplingTargets(): void
    {
        // 1) Mock Guzzle client for SamplingTargets
        $mockHttp = $this->createMock(\GuzzleHttp\Client::class);
        $mockHttp->expects($this->once())
            ->method('post')
            ->with('SamplingTargets', $this->arrayHasKey('json'))
            ->willReturn(new Response(200, [], $this->targetsJson));

        // 2) Instantiate and inject mock
        $client = new AWSXRaySamplerClient('https://xray');
        $ref    = new ReflectionClass($client);
        $prop   = $ref->getProperty('httpClient');
        $prop->setAccessible(true);
        $prop->setValue($client, $mockHttp);

        // 3) Build a sample SamplingStatisticsDocument
        $statDoc = new SamplingStatisticsDocument(
            'client1',
            'test',
            10,
            5,
            2,
            1234.0
        );

        // 4) Call method under test
        $resp = $client->getSamplingTargets([$statDoc]);

        // 5) Assertions on returned stdClass
        $this->assertIsObject($resp);
        $this->assertSame(1707551387.0, $resp->LastRuleModification);
        $this->assertObjectHasProperty('SamplingTargetDocuments', $resp);
        $this->assertCount(2, $resp->SamplingTargetDocuments);

        $t1 = $resp->SamplingTargetDocuments[0];
        $this->assertSame('test', $t1->RuleName);
        $this->assertSame(30,     $t1->ReservoirQuota);
        $this->assertSame(0.10,   $t1->FixedRate);

        $t2 = $resp->SamplingTargetDocuments[1];
        $this->assertSame('Default', $t2->RuleName);
        $this->assertSame(0,         $t2->ReservoirQuota);
        $this->assertSame(0.05,      $t2->FixedRate);
    }
}
