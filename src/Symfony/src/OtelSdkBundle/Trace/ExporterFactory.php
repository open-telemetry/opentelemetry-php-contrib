<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\OtelSdkBundle\Trace;

use Http\Discovery\HttpClientDiscovery;
use Http\Discovery\Psr17FactoryDiscovery;
use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\Factory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use Symfony\Component\OptionsResolver\Options;
use Throwable;

/** @phan-file-suppress PhanUndeclaredClassInstanceof */
/** @phan-file-suppress PhanUndeclaredClassReference */
/** @phan-file-suppress PhanUndeclaredTypeReturnType */
class ExporterFactory implements Factory\GenericFactoryInterface
{
    private const ENDPOINT_URL_PARAM = 'endpoint_url';
    private const NAME_PARAM = 'name';
    private const OPTIONS_MAPPING = [
        self::ENDPOINT_URL_PARAM => 'url',
        self::NAME_PARAM => 'service_name',
    ];

    use Factory\GenericFactoryTrait;

    public function build(array $options = []): SpanExporterInterface
    {
        try {
            $res = $this->doBuild($options);
            if (!$res instanceof SpanExporterInterface) {
                throw new RuntimeException(
                    sprintf(
                        'Built object (%s) is not an instance of %s',
                        get_class($res),
                        SpanExporterInterface::class
                    )
                );
            }

            return $res;
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Could not build SpanExporter',
                (int) $e->getCode(),
                $e
            );
        }
    }

    public function __invoke(array $options = []): SpanExporterInterface
    {
        return $this->build($options);
    }

    /**
     * @return void
     */
    private function parameterCallback(ReflectionParameter $parameter, string $option)
    {
        $name = self::camelToSnakeCase($parameter->getName());
        $type = $parameter->getType();

        if (array_key_exists($name, self::OPTIONS_MAPPING)) {
            $this->getOptionsResolver()->setDefined(self::OPTIONS_MAPPING[$name]);
            $this->setDefault($name, function (Options $options) use ($name) {
                return $options[self::OPTIONS_MAPPING[$name]];
            });
        }

        if ($type instanceof ReflectionNamedType) {
            switch ($type->getName()) {
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
}
