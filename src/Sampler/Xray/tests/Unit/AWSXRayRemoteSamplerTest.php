<?php

declare(strict_types=1);

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Sampler\Xray\_AWSXRayRemoteSampler;
use OpenTelemetry\Contrib\Sampler\Xray\AWSXRaySamplerClient;
use OpenTelemetry\Contrib\Sampler\Xray\Clock;
use OpenTelemetry\Contrib\Sampler\Xray\FallbackSampler;
use OpenTelemetry\Contrib\Sampler\Xray\RulesCache;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SamplingResult;
use PHPUnit\Framework\TestCase;

/** @psalm-suppress UnusedMethodCall */
final class AWSXRayRemoteSamplerTest extends TestCase
{
    public function testShouldSampleUpdatesRulesAndTargets(): void
    {
        $resource = ResourceInfo::create(Attributes::create([]));
        
        // 1) Mock client
        $mockClient = $this->createMock(AWSXRaySamplerClient::class);
        $dummyRules = ['rule1', 'rule2'];
        $mockClient->expects($this->once())
            ->method('getSamplingRules')
            ->willReturn($dummyRules);
        $mockClient->expects($this->once())
            ->method('getSamplingTargets')
            ->willReturn((object) [
                'SamplingTargetDocuments' => [],
                'Interval' => 5,
                'LastRuleModification' => 0,
            ]);

        // 2) Mock RulesCache
        $mockRulesCache = $this->getMockBuilder(RulesCache::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockRulesCache->expects($this->once())
            ->method('updateRules')
            ->with($dummyRules);
        $mockRulesCache->expects($this->once())
            ->method('getAppliers')
            ->willReturn([]);
        $mockRulesCache->expects($this->once())
            ->method('updateTargets')
            ->with([]);
        $mockRulesCache->expects($this->once())
            ->method('expired')
            ->willReturn(false);
        $expectedResult = new SamplingResult(SamplingResult::RECORD_AND_SAMPLE);
        $mockRulesCache->expects($this->once())
            ->method('shouldSample')
            ->willReturn($expectedResult);

        // 3) Instantiate and inject mocks
        $sampler = new _AWSXRayRemoteSampler($resource, 'host', 0);
        $ref = new ReflectionClass($sampler);
        $ref->getProperty('client')->setAccessible(true);
        $ref->getProperty('client')->setValue($sampler, $mockClient);
        $ref->getProperty('rulesCache')->setAccessible(true);
        $ref->getProperty('rulesCache')->setValue($sampler, $mockRulesCache);
        $ref->getProperty('fallback')->setAccessible(true);
        $ref->getProperty('fallback')->setValue($sampler, $this->createMock(FallbackSampler::class));

        // 4) Force fetch times into the past so updates run
        $now = (new Clock())->now();
        $ref->getProperty('nextRulesFetchTime')->setAccessible(true);
        $ref->getProperty('nextRulesFetchTime')->setValue($sampler, $now->sub(new DateInterval('PT1S')));
        $ref->getProperty('nextTargetFetchTime')->setAccessible(true);
        $ref->getProperty('nextTargetFetchTime')->setValue($sampler, $now->sub(new DateInterval('PT1S')));

        // 5) Call shouldSample
        $result = $sampler->shouldSample(
            $this->createMock(ContextInterface::class),
            'traceId',
            'spanName',
            1,
            Attributes::create([]),
            []
        );
        $this->assertSame($expectedResult, $result);
    }

    public function testShouldSampleFallbackWhenExpired(): void
    {
        $resource = ResourceInfo::create(Attributes::create([]));
        $mockClient = $this->createMock(AWSXRaySamplerClient::class);

        $mockRulesCache = $this->getMockBuilder(RulesCache::class)
            ->disableOriginalConstructor()
            ->getMock();
        // No updateRules call since expired
        $mockRulesCache->expects($this->never())
            ->method('updateRules');
        $mockRulesCache->expects($this->once())
            ->method('expired')
            ->willReturn(true);

        $fallback = $this->createMock(FallbackSampler::class);
        $expected = new SamplingResult(SamplingResult::DROP);
        $fallback->expects($this->once())
            ->method('shouldSample')
            ->willReturn($expected);

        $sampler = new _AWSXRayRemoteSampler($resource, 'host', 0);
        $ref = new ReflectionClass($sampler);
        $ref->getProperty('client')->setAccessible(true);
        $ref->getProperty('client')->setValue($sampler, $mockClient);
        $ref->getProperty('rulesCache')->setAccessible(true);
        $ref->getProperty('rulesCache')->setValue($sampler, $mockRulesCache);
        $ref->getProperty('fallback')->setAccessible(true);
        $ref->getProperty('fallback')->setValue($sampler, $fallback);

        $result = $sampler->shouldSample(
            $this->createMock(ContextInterface::class),
            't',
            'n',
            1,
            Attributes::create([]),
            []
        );
        $this->assertSame($expected, $result);
    }
}
