<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Propagation\Instana\Integration;

use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Baggage\Metadata;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\TraceFlags;

use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use OpenTelemetry\Contrib\Propagation\Instana\InstanaPropagator;

use OpenTelemetry\SDK\Trace\Span;
use Override;
use PHPUnit\Framework\TestCase;

final class InstanaMultiPropagatorTest extends TestCase
{
    private const X_INSTANA_T = 'ff000000000000000000000000000041';
    private const X_INSTANA_S = 'ff00000000000041';

    private $TRACE_ID;
    private $SPAN_ID;
    private $SAMPLED;

    private InstanaPropagator $InstanaPropagator;
    
    #[Override]
    protected function setUp(): void
    {
        $this->InstanaPropagator = InstanaPropagator::getInstance();
        $instanaMultiFields = $this->InstanaPropagator->fields();
        $this->TRACE_ID = $instanaMultiFields[0];
        $this->SPAN_ID = $instanaMultiFields[1];
        $this->SAMPLED = $instanaMultiFields[2];
    }

    /**
    * @dataProvider sampledValueProvider
    */
    public function test_extract_sampled_context_with_baggage($sampledValue): void
    {
        $carrier = [
            $this->TRACE_ID => self::X_INSTANA_T,
            $this->SPAN_ID => self::X_INSTANA_S,
            $this->SAMPLED => $sampledValue,
            'baggage' => 'user_id=12345,request_id=abcde',
        ];
        $propagator = new MultiTextMapPropagator([InstanaPropagator::getInstance(), BaggagePropagator::getInstance()]);
        $context = $propagator->extract($carrier);

        $this->assertEquals(
            SpanContext::createFromRemoteParent(self::X_INSTANA_T, self::X_INSTANA_S, TraceFlags::SAMPLED),
            $this->getSpanContext($this->InstanaPropagator->extract($carrier))
        );

        // Verify baggage
        $baggage = Baggage::fromContext($context);
        $this->assertEquals('12345', $baggage->getValue('user_id'));
        $this->assertEquals('abcde', $baggage->getValue('request_id'));

        $arr = [];

        foreach ($baggage->getAll() as $key => $value) {
            $arr[$key] = $value->getValue();
        }

        $this->assertEquals(
            ['user_id' => '12345', 'request_id' => 'abcde'],
            $arr
        );
    }

    /**
    * @dataProvider sampledValueProvider
    */
    public function test_extract_sampled_context_with_baggage_but_instana_propagator($sampledValue): void
    {
        $carrier = [
            $this->TRACE_ID => self::X_INSTANA_T,
            $this->SPAN_ID => self::X_INSTANA_S,
            $this->SAMPLED => $sampledValue,
            'baggage' => 'user_id=12345,request_id=abcde',
        ];
        $context = $this->InstanaPropagator->extract($carrier);

        $this->assertEquals(
            SpanContext::createFromRemoteParent(self::X_INSTANA_T, self::X_INSTANA_S, TraceFlags::SAMPLED),
            $this->getSpanContext($this->InstanaPropagator->extract($carrier))
        );

        // Verify baggage is not propagated
        $baggage = Baggage::fromContext($context);
        $this->assertNull($baggage->getValue('user_id'));
        $this->assertNull($baggage->getValue('request_id'));

    }

    public function test_baggage_inject(): void
    {
        $carrier = [];

        $propagator = new MultiTextMapPropagator([InstanaPropagator::getInstance(), BaggagePropagator::getInstance()]);

        $propagator->inject(
            $carrier,
            null,
            Context::getRoot()->withContextValue(
                Baggage::getBuilder()
                    ->set('nometa', 'nometa-value')
                    ->set('meta', 'meta-value', new Metadata('somemetadata; someother=foo'))
                    ->build()
            )
        );

        $this->assertSame(
            ['baggage' => 'nometa=nometa-value,meta=meta-value;somemetadata; someother=foo'],
            $carrier
        );
    }

    public static function sampledValueProvider(): array
    {
        return [
            'String sampled value' => ['1'],
            'Boolean(lower string) sampled value' => ['true'],
            'Boolean(upper string) sampled value' => ['TRUE'],
            'Boolean(camel string) sampled value' => ['True'],
        ];
    }

    private function getSpanContext(ContextInterface $context): SpanContextInterface
    {
        return Span::fromContext($context)->getContext();
    }

}
