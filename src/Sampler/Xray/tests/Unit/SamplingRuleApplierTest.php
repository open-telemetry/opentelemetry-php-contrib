<?php

declare(strict_types=1);

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Sampler\Xray\SamplingRule;
use OpenTelemetry\Contrib\Sampler\Xray\SamplingRuleApplier;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\TestCase;

/** @psalm-suppress UnusedMethodCall */
final class SamplingRuleApplierTest extends TestCase
{
    public function testWildcardRuleMatchesAnyAttributesOldSemanticConventions(): void
    {
        // Rule with all wildcards and no specific attributes
        $rule = new SamplingRule(
            'wildcard',
            1,
            0.5,
            0,
            '*',
            '*',
            '*',
            '*',
            '*',
            '*',
            1,
            []
        );
        $applier = new SamplingRuleApplier('client', $rule);

        // Attributes that should all match '*'
        $attrs = Attributes::create([
            TraceAttributes::HTTP_METHOD => 'POST',
            TraceAttributes::HTTP_TARGET => '/foo/bar',
            TraceAttributes::HTTP_HOST   => 'example.com',
        ]);
        $resource = ResourceInfo::create(Attributes::create([
            TraceAttributes::SERVICE_NAME   => 'AnyService',
            TraceAttributes::CLOUD_PLATFORM => 'aws_lambda',
        ]));

        $this->assertTrue(
            $applier->matches($attrs, $resource),
            'Wildcard rule should match any attributes'
        );
    }

    public function testSpecificRuleMatchesExactAttributesOldSemanticConventions(): void
    {
        // Rule with specific matching values
        $rule = new SamplingRule(
            'specific',
            1,
            0.5,
            0,
            'example.com',
            'GET',
            'arn:aws:ecs:123',
            'MyService',
            'AWS::ECS::Container',
            '/api/test',
            1,
            ['env' => 'prod']
        );
        $applier = new SamplingRuleApplier('client', $rule);

        // Matching attributes
        $attrs = Attributes::create([
            TraceAttributes::HTTP_METHOD => 'GET',
            TraceAttributes::HTTP_URL    => 'https://example.com/api/test?x=1',
            TraceAttributes::HTTP_HOST   => 'example.com',
            'env' => 'prod',
        ]);
        $resource = ResourceInfo::create(Attributes::create([
            TraceAttributes::SERVICE_NAME   => 'MyService',
            TraceAttributes::CLOUD_PLATFORM => 'aws_ecs',
            'aws.ecs.container.arn'         => 'arn:aws:ecs:123',
        ]));

        $this->assertTrue(
            $applier->matches($attrs, $resource),
            'Specific rule should match when all values line up'
        );
    }

    public function testWildcardRuleMatchesAnyAttributesNewSemanticConventions(): void
    {
        // Rule with all wildcards and no specific attributes
        $rule = new SamplingRule(
            'wildcard',
            1,
            0.5,
            0,
            '*',
            '*',
            '*',
            '*',
            '*',
            '*',
            1,
            []
        );
        $applier = new SamplingRuleApplier('client', $rule);

        // Attributes that should all match '*'
        $attrs = Attributes::create([
            TraceAttributes::HTTP_REQUEST_METHOD => 'POST',
            TraceAttributes::URL_PATH => '/foo/bar',
            TraceAttributes::SERVER_ADDRESS   => 'example.com',
        ]);
        $resource = ResourceInfo::create(Attributes::create([
            TraceAttributes::SERVICE_NAME   => 'AnyService',
            TraceAttributes::CLOUD_PLATFORM => 'aws_lambda',
        ]));

        $this->assertTrue(
            $applier->matches($attrs, $resource),
            'Wildcard rule should match any attributes'
        );
    }

    public function testSpecificRuleMatchesExactAttributesNewSemanticConventions(): void
    {
        // Rule with specific matching values
        $rule = new SamplingRule(
            'specific',
            1,
            0.5,
            0,
            'example.com',
            'GET',
            'arn:aws:ecs:123',
            'MyService',
            'AWS::ECS::Container',
            '/api/test',
            1,
            ['env' => 'prod']
        );
        $applier = new SamplingRuleApplier('client', $rule);

        // Matching attributes
        $attrs = Attributes::create([
            TraceAttributes::HTTP_REQUEST_METHOD => 'GET',
            TraceAttributes::URL_FULL    => 'https://example.com/api/test?x=1',
            TraceAttributes::SERVER_ADDRESS   => 'example.com',
            'env' => 'prod',
        ]);
        $resource = ResourceInfo::create(Attributes::create([
            TraceAttributes::SERVICE_NAME   => 'MyService',
            TraceAttributes::CLOUD_PLATFORM => 'aws_ecs',
            'aws.ecs.container.arn'         => 'arn:aws:ecs:123',
        ]));

        $this->assertTrue(
            $applier->matches($attrs, $resource),
            'Specific rule should match when all values line up'
        );
    }

    public function testRuleDoesNotMatchWhenOneAttributeDiffers(): void
    {
        // Same rule as above
        $rule = new SamplingRule(
            'specific',
            1,
            0.5,
            0,
            'example.com',
            'GET',
            'arn:aws:ecs:123',
            'MyService',
            'AWS::ECS::Container',
            '/api/test',
            1,
            ['env' => 'prod']
        );
        $applier = new SamplingRuleApplier('client', $rule);

        // Attributes with wrong HTTP method
        $attrs = Attributes::create([
            TraceAttributes::HTTP_METHOD => 'POST',
            TraceAttributes::HTTP_URL    => 'https://example.com/api/test',
            TraceAttributes::HTTP_HOST   => 'example.com',
            'env' => 'prod',
        ]);
        $resource = ResourceInfo::create(Attributes::create([
            TraceAttributes::SERVICE_NAME   => 'MyService',
            TraceAttributes::CLOUD_PLATFORM => 'aws_ecs',
            'aws.ecs.container.arn'         => 'arn:aws:ecs:123',
        ]));

        $this->assertFalse(
            $applier->matches($attrs, $resource),
            'Rule should not match when HTTP method differs'
        );
    }

    public function testShouldSample_incrementsStatistics_andHonorsReservoirSamplerDecision(): void
    {
        $rule = new SamplingRule('r', 1, 0.0, 1, '*', '*', '*', '*', '*', '*', 1, []);
        $applier = new SamplingRuleApplier('c', $rule, null);

        // Mock reservoirSampler to RECORD
        $reservoirMock = $this->createMock(SamplerInterface::class);
        $reservoirMock->method('shouldSample')
            ->willReturn(new SamplingResult(SamplingResult::RECORD_AND_SAMPLE, [], null));
        // Mock fixedRateSampler to DROP (should not be used)
        $fixedMock = $this->createMock(SamplerInterface::class);
        $fixedMock->method('shouldSample')
            ->willReturn(new SamplingResult(SamplingResult::DROP, [], null));

        // Inject mocks via reflection
        $ref = new \ReflectionClass($applier);
        $propRes = $ref->getProperty('reservoirSampler');
        $propRes->setAccessible(true);
        $propRes->setValue($applier, $reservoirMock);
        $propFix = $ref->getProperty('fixedRateSampler');
        $propFix->setAccessible(true);
        $propFix->setValue($applier, $fixedMock);
        // Ensure borrowing = true
        $propBorrow = $ref->getProperty('borrowing');
        $propBorrow->setAccessible(true);
        $propBorrow->setValue($applier, true);

        $context = $this->createMock(ContextInterface::class);
        $attributes = Attributes::create([]);

        // Perform sampling
        $result = $applier->shouldSample($context, 'trace', 'span', 0, $attributes, []);
        $this->assertSame(SamplingResult::RECORD_AND_SAMPLE, $result->getDecision());

        // Snapshot statistics
        $now = Clock::getDefault()->now();
        $statsDoc = $applier->snapshot($now);

        $this->assertSame(1, $statsDoc->RequestCount);
        $this->assertSame(1, $statsDoc->SampleCount);
        $this->assertSame(1, $statsDoc->BorrowCount);
    }

    public function testShouldSample_onReservoirDrop_usesFixedRateSampler_andIncrementsSampleCountOnly(): void
    {
        $rule = new SamplingRule('r2', 1, 1.0, 0, '*', '*', '*', '*', '*', '*', 1, []);
        $applier = new SamplingRuleApplier('c2', $rule, null);

        // reservoirSampler: always DROP
        $reservoirMock = $this->createMock(SamplerInterface::class);
        $reservoirMock->method('shouldSample')
            ->willReturn(new SamplingResult(SamplingResult::DROP, [], null));
        // fixedRateSampler: RECORD
        $fixedMock = $this->createMock(SamplerInterface::class);
        $fixedMock->method('shouldSample')
            ->willReturn(new SamplingResult(SamplingResult::RECORD_AND_SAMPLE, [], null));

        $ref = new \ReflectionClass($applier);
        $propRes = $ref->getProperty('reservoirSampler');
        $propRes->setAccessible(true);
        $propRes->setValue($applier, $reservoirMock);
        $propFix = $ref->getProperty('fixedRateSampler');
        $propFix->setAccessible(true);
        $propFix->setValue($applier, $fixedMock);

        $context = $this->createMock(ContextInterface::class);
        $attributes = Attributes::create([]);

        $result = $applier->shouldSample($context, 't2', 's2', 0, $attributes, []);
        $this->assertSame(SamplingResult::RECORD_AND_SAMPLE, $result->getDecision());

        $now = Clock::getDefault()->now();
        $statsDoc = $applier->snapshot($now);
        $this->assertSame(1, $statsDoc->RequestCount);
        $this->assertSame(1, $statsDoc->SampleCount);
        $this->assertSame(0, $statsDoc->BorrowCount);
    }

    public function testSnapshot_resetsStatisticsAfterCapture(): void
    {
        $rule = new SamplingRule('r3', 1, 0.0, 1, '*', '*', '*', '*', '*', '*', 1, []);
        $applier = new SamplingRuleApplier('c3', $rule, null);

        // simulate stats by reflection
        $refStats = new \ReflectionProperty($applier, 'statistics');
        $refStats->setAccessible(true);
        $stats = $refStats->getValue($applier);
        $stats->requestCount = 5;
        $stats->sampleCount  = 2;
        $stats->borrowCount  = 1;

        $now = Clock::getDefault()->now();
        $doc1 = $applier->snapshot($now);
        $this->assertSame(5, $doc1->RequestCount);
        $this->assertSame(2, $doc1->SampleCount);
        $this->assertSame(1, $doc1->BorrowCount);

        // After snapshot, internal counters should reset
        $doc2 = $applier->snapshot($now);
        $this->assertSame(0, $doc2->RequestCount);
        $this->assertSame(0, $doc2->SampleCount);
        $this->assertSame(0, $doc2->BorrowCount);
    }
}
