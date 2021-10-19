<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\Symfony\OtelSdkBundle\Trace;

use Http\Discovery\HttpClientDiscovery;
use Http\Discovery\Psr17FactoryDiscovery;
use OpenTelemetry\Instrumentation\Symfony\OtelSdkBundle\Factory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use ReflectionParameter;
use RuntimeException;
use Symfony\Component\OptionsResolver\Options;
use Throwable;

class ExporterFactory implements Factory\GenericFactoryInterface
{
    use Factory\GenericFactoryTrait;

    public function build(array $options = []): SpanExporterInterface
    {
        try {
            $res = $this->doBuild($options);
            if (!$res instanceof SpanExporterInterface) {
                throw new RuntimeException(
                    sprintf('Built object is not an instance of %s', SpanExporterInterface::class)
                );
            }

            return $res;
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Could not build SpanExporter',
                $e->getCode(),
                $e
            );
        }
    }

    private function parameterCallback(ReflectionParameter $parameter, string $option)
    {
        if (!$type = $parameter->getType()) {
            return;
        }

        switch ($type) {
            case ClientInterface::class:
                $this->setDefault($option, fn (Options $options) => HttpClientDiscovery::find());

                return;
            case RequestFactoryInterface::class:
                $this->setDefault($option, fn (Options $options) => Psr17FactoryDiscovery::findRequestFactory());

                return;
            case StreamFactoryInterface::class:
                $this->setDefault($option, fn (Options $options) => Psr17FactoryDiscovery::findStreamFactory());

                return;
        }
    }
}
