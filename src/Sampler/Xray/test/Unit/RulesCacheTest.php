<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use OpenTelemetry\Contrib\Sampler\Xray\RulesCache;
use OpenTelemetry\Contrib\Sampler\Xray\SamplingRule;
use OpenTelemetry\Contrib\Sampler\Xray\Clock;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;

final class RulesCacheTest extends TestCase
{
    private Clock $clock;
    private ResourceInfo $resource;

    protected function setUp(): void
    {
        $this->clock = new Clock();
        $this->resource = ResourceInfo::create(Attributes::create([
            'service.name'   => 'test-service',
            'cloud.platform' => 'aws_ecs'
        ]));
    }

    public function testUpdateRulesSortsByPriorityThenName(): void
    {
        $fallback = new AlwaysOffSampler();
        $cache = new RulesCache($this->clock, 'client', $this->resource, $fallback);

        $rule1 = new SamplingRule('b', 2, 0.1, 0, '*','*','*','*','*','*', 1, []);
        $rule2 = new SamplingRule('a', 1, 0.1, 0, '*','*','*','*','*','*', 1, []);
        $rule3 = new SamplingRule('c', 1, 0.1, 0, '*','*','*','*','*','*', 1, []);

        // Provide in unsorted order
        $cache->updateRules([$rule1, $rule2, $rule3]);

        $names = array_map(
            fn($ap) => $ap->getRuleName(),
            $cache->getAppliers()
        );

        // Expected order: a (prio1), c (prio1, name 'c' > 'a'), b (prio2)
        $this->assertEquals(['a', 'c', 'b'], $names);
    }

    public function testUpdateRulesReusesExistingAppliers(): void
    {
        $fallback = new AlwaysOffSampler();
        $cache = new RulesCache($this->clock, 'client', $this->resource, $fallback);

        $ruleA1 = new SamplingRule('ruleA', 1, 0.1, 0, '*','*','*','*','*','*',1,[]);
        $ruleB  = new SamplingRule('ruleB', 1, 0.1, 0, '*','*','*','*','*','*',1,[]);

        $cache->updateRules([$ruleA1, $ruleB]);
        $appliers1 = $cache->getAppliers();

        // Now update rules: change ruleA's FixedRate and remove ruleB; add ruleC
        $ruleA2 = new SamplingRule('ruleA', 1, 0.5, 0, '*','*','*','*','*','*',1,[]);
        $ruleC  = new SamplingRule('ruleC', 2, 0.1, 0, '*','*','*','*','*','*',1,[]);

        $cache->updateRules([$ruleA2, $ruleC]);
        $appliers2 = $cache->getAppliers();

        // Extract applier objects for ruleA
        $a1 = array_filter($appliers1, fn($a) => $a->getRuleName() === 'ruleA');
        $a2 = array_filter($appliers2, fn($a) => $a->getRuleName() === 'ruleA');
        $this->assertCount(1, $a1);
        $this->assertCount(1, $a2);
        $a1 = array_shift($a1);
        $a2 = array_shift($a2);

        // The applier for ruleA should be the same instance (reused)
        $this->assertSame($a1, $a2);

        // ruleB should be removed, ruleC added
        $names2 = array_map(fn($a) => $a->getRuleName(), $appliers2);
        $this->assertNotContains('ruleB', $names2);
        $this->assertContains('ruleC', $names2);
    }

    public function testUpdateTargetsClonesMatchingAppliers(): void
    {
        $fallback = new AlwaysOffSampler();
        $cache = new RulesCache($this->clock, 'client', $this->resource, $fallback);

        $ruleA = new SamplingRule('ruleA', 1, 0.1, 5, '*','*','*','*','*','*',1,[]);
        $ruleB = new SamplingRule('ruleB', 1, 0.1, 5, '*','*','*','*','*','*',1,[]);

        $cache->updateRules([$ruleA, $ruleB]);
        $appliers1 = $cache->getAppliers();

        // Prepare a dummy target doc for ruleA
        $targetDoc = (object)[
            'RuleName'         => 'ruleA',
            'FixedRate'        => 0.2,
            'ReservoirQuota'   => 2,
            'ReservoirQuotaTTL'=> time() + 60,
            'Interval'         => 15,
        ];
        $cache->updateTargets(['ruleA' => $targetDoc]);
        $appliers2 = $cache->getAppliers();

        // ruleA applier should be a new instance
        $a1 = array_filter($appliers1, fn($a) => $a->getRuleName() === 'ruleA');
        $a2 = array_filter($appliers2, fn($a) => $a->getRuleName() === 'ruleA');
        $a1 = array_shift($a1);
        $a2 = array_shift($a2);
        $this->assertNotSame($a1, $a2);

        // ruleB applier should be unchanged
        $b1 = array_filter($appliers1, fn($a) => $a->getRuleName() === 'ruleB');
        $b2 = array_filter($appliers2, fn($a) => $a->getRuleName() === 'ruleB');
        $b1 = array_shift($b1);
        $b2 = array_shift($b2);
        $this->assertSame($b1, $b2);
    }
}
