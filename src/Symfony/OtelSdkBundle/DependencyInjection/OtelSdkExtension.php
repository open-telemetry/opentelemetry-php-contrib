<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection;

use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Trace;
use OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration as Conf;
use OpenTelemetry\Symfony\OtelSdkBundle\Trace\ExporterFactory;
use OpenTelemetry\Symfony\OtelSdkBundle\Util\ServiceHelper;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Throwable;

/**
 * @codeCoverageIgnore
 */
class OtelSdkExtension extends Extension implements LoggerAwareInterface
{
    public const SDK_CONFIG_FILE = __DIR__ . '/../Resources/config/sdk.php';
    public const DEBUG_CONFIG_FILE = __DIR__ . '/../Resources/config/tracer_debug.php';
    private const FACTORY_SUFFIX = 'factory';
    private const PROVIDER_ARG_PROCESSOR = 0;
    private const PROCESSOR_ARG_EXPORTER = 0;
    private const DEFAULT_SERVICE_NAME = 'SymfonyApplication';
    private const DEV_ENVIRONMENT  = 'dev';

    private string $serviceName = self::DEFAULT_SERVICE_NAME;
    private ContainerBuilder $container;
    private array $config;
    private array $processors = [];
    private ?LoggerInterface $logger = null;

    /**
     * @param array $config
     * @param ContainerBuilder $container
     * @return Configuration
     */
    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        return new Conf(
            (bool) $container->getParameter('kernel.debug'),
            $this->logger
        );
    }

    /**
     * @param array $configs
     * @param ContainerBuilder $container
     */
    final public function load(array $configs, ContainerBuilder $container): void
    {
        $this->loadInternal(
            $this->processConfiguration(
                $this->getConfiguration(
                    $configs,
                    $container
                ),
                $configs
            ),
            $container
        );
    }

    /**
     * @param array $mergedConfig
     * @param ContainerBuilder $container
     */
    protected function loadInternal(array $mergedConfig, ContainerBuilder $container): void
    {
        if ($mergedConfig['enabled'] === false) {
            return;
        }

        $this->setContainer($container);
        $this->debug('Merged config', $mergedConfig);
        $this->setConfig($mergedConfig);
        $this->loadDefaultConfig();
        $this->configureResourceInfo();
        $this->configureTraceSamplers();
        $this->configureSpanLimits();
        $this->configureSpanProcessors();
        $this->loadDebugConfig();
        $this->configureSpanExporters();
    }

    private function configureResourceInfo(): void
    {
        $config = $this->config[Conf::RESOURCE_NODE] ?? [];
        if (empty($config)) {
            return;
        }
        // configure resource attributes limits
        if (isset($config[Conf::LIMITS_NODE])) {
            $this->getDefinitionByClass(Trace\SpanLimitsBuilder::class)
                ->addMethodCall('setAttributeCountLimit', [$config[Conf::LIMITS_NODE][Conf::ATTR_COUNT_NODE]])
                ->addMethodCall('setAttributeValueLengthLimit', [$config[Conf::LIMITS_NODE][Conf::ATTR_VALUE_LENGTH_NODE]]);
        }
        // configure resource attributes
        $attributesParams = (array) $this->getContainer()->getParameter(Parameters::RESOURCE_ATTRIBUTES);
        foreach ($config[Conf::ATTRIBUTES_NODE] as $attribute => $value) {
            $attributesParams[$attribute] = $value;
        }
        $this->getContainer()->setParameter(Parameters::RESOURCE_ATTRIBUTES, $attributesParams);

        $attributes = $this->getDefinitionByClass(Attributes::class);
        $attributes->setArguments([
            '%' . Parameters::RESOURCE_ATTRIBUTES . '%',
            0,
        ]);

        // reference service name for later use
        if (isset($config[Conf::ATTRIBUTES_NODE], $config[Conf::ATTRIBUTES_NODE][Conf::SERVICE_NAME_ATTR])) {
            $this->serviceName = $config[Conf::ATTRIBUTES_NODE][Conf::SERVICE_NAME_ATTR];
        }
    }

    private function configureTraceSamplers(): void
    {
        $config = $this->config[Conf::TRACE_NODE][Conf::SAMPLER_NODE] ?? [];
        if (empty($config)) {
            return;
        }

        $arguments = [];

        $arguments[] = isset($config[Conf::ROOT_NODE])
            ? $this->createTraceSamplerReference($config[Conf::ROOT_NODE])
            : self::createReferenceFromClass(Samplers::DEFAULT_SAMPLER);

        foreach ([Conf::REMOTE_NODE, Conf::LOCAL_NODE] as $origin) {
            foreach ([Conf::SAMPLED_NODE, Conf::NOT_SAMPLED_NODE] as $sampled) {
                $arguments[] = isset($config[$origin]) && isset($config[$origin][$sampled])
                    ? $this->createTraceSamplerReference($config[$origin][$sampled])
                    : null;
            }
        }

        $this->getDefinitionByClass(Samplers::PARENT_BASED)
            ->setArguments($arguments);

        $this->getDefinitionByClass(Trace\TracerProvider::class)
            ->setArgument(1, self::createReferenceFromClass(Samplers::PARENT_BASED));
    }

    private function configureSpanLimits(): void
    {
        $config = $this->config[Conf::TRACE_NODE][Conf::SPAN_NODE][Conf::LIMITS_NODE] ?? [];
        if (empty($config)) {
            return;
        }

        $builder = $this->getDefinitionByClass(Trace\SpanLimitsBuilder::class);

        foreach (Conf::SPAN_LIMIT_ATTRS as $attribute) {
            if (isset($config[$attribute])) {
                $setter = sprintf('set%s', Container::camelize($attribute));
                if (method_exists(Trace\SpanLimitsBuilder::class, $setter)) {
                    throw new RuntimeException(sprintf(
                        'Cannot configure attribute "%s" on LimitsBuilder. Method "%s" not found.',
                        $attribute,
                        $setter
                    ));
                }
                $builder->addMethodCall($setter, [(int) $config[$attribute]]);
            }
        }
    }

    private function configureSpanProcessors(): void
    {
        $this->setStandardSpanProcessors();

        $config = $this->config[Conf::TRACE_NODE][Conf::SPAN_NODE][Conf::PROCESSORS_NODE]
            ?? [];
        if (empty($config)) {
            return;
        }

        foreach ($config as $key => $conf) {
            $type = $conf[Conf::TYPE_NODE];
            if ($type === Conf::DEFAULT_TYPE || in_array($type, Conf::PROCESSOR_NODE_VALUES)) {
                if ($type === Conf::DEFAULT_TYPE) {
                    $type = Conf::PROCESSOR_DEFAULT;
                }
                $this->setSpanProcessorByClass((string) $key, ConfigMappings::SPAN_PROCESSORS[$type]);

                continue;
            }
            if ($type === Conf::CUSTOM_TYPE) {
                if (isset($conf[Conf::CLASS_NODE])) {
                    $this->setSpanProcessorByClass((string) $key, $conf[Conf::CLASS_NODE], $conf[Conf::OPTIONS_NODE]);

                    continue;
                }
                if (isset($conf[Conf::ID_NODE])) {
                    $id = self::normalizeStringReference($conf[Conf::ID_NODE]);
                    $this->validateId($id);
                    $this->setSpanProcessor((string) $key, $this->getContainer()->getDefinition($id));

                    continue;
                }
            }

            throw new RuntimeException(sprintf('Invalid span processor type %s', $type));
        }
    }

    private function configureSpanExporters(): void
    {
        $config = $this->config[Conf::TRACE_NODE][Conf::EXPORTERS_NODE]
            ?? [];
        if (empty($config)) {
            return;
        }

        $processorRefs = [];

        foreach ($config as $exporterKey => $conf) {
            $processor = $this->getSpanProcessorDefinition($conf[Conf::PROCESSOR_NODE]);
            $processorId = $this->registerSpanProcessor($processor, $conf[Conf::PROCESSOR_NODE], (string) $exporterKey);
            $processorRefs[] = $this->createValidatedReference($processorId);
            $exporterReference = $this->resolveExporterReference((string) $exporterKey, $conf);
            $processor->setArgument(self::PROCESSOR_ARG_EXPORTER, $exporterReference);
        }

        $this->getDefinitionByClass(Trace\TracerProvider::class)
            ->setArgument(self::PROVIDER_ARG_PROCESSOR, $processorRefs);
    }

    /**
     * @param string $exporterKey
     * @param array $config
     * @return Reference
     */
    private function resolveExporterReference(string $exporterKey, array $config): Reference
    {
        if ($this->isExporterClassConfiguration($config)) {
            return $this->createExporterClassReference($exporterKey, $config);
        }
        if ($this->isExporterReferenceConfiguration($config)) {
            return $this->createValidatedReference(
                $config[Conf::ID_NODE]
            );
        }

        throw new RuntimeException(
            sprintf('Could not resolve span exporter %s', $exporterKey)
        );
    }

    /**
     * @param string $exporterKey
     * @param array $config
     * @return Reference
     */
    private function createExporterClassReference(string $exporterKey, array $config): Reference
    {
        return $this->createValidatedReference(
            $this->createAndRegisterExporterClassDefinition($exporterKey, $config)
        );
    }

    /**
     * @param string $exporterKey
     * @param array $config
     * @return string
     */
    private function createAndRegisterExporterClassDefinition(string $exporterKey, array $config): string
    {
        $id = $this->createExporterId($exporterKey);
        $options = $this->normalizeExporterOptions($config);
        $definition =  self::createDefinition($this->resolveExporterClass($config))
            ->setFactory(
                $this->createValidatedReference(
                    $this->registerExporterFactoryDefinition($exporterKey, $config)
                )
            )
            ->setArguments([$options]);

        $this->registerService($id, $definition, false);

        return $id;
    }

    /**
     * @param array $config
     * @return array
     */
    private function normalizeExporterOptions(array $config): array
    {
        $definedOptions = (ExporterFactory::create($this->resolveExporterClass($config)))
            ->getOptionsResolver()
            ->getDefinedOptions();

        $options = [];
        foreach ($config[Conf::OPTIONS_NODE] as $key => $value) {
            if (!in_array($key, $definedOptions, true)) {
                throw new RuntimeException(
                    sprintf(
                        'Option "%s" is not allowed for span exporter of type %s"',
                        $key,
                        $config[Conf::CLASS_NODE] ?? $config[Conf::TYPE_NODE]
                    )
                );
            }

            $options[$key] = $value;
        }
        if (isset($config[Conf::URL_NODE])) {
            $options[Conf::URL_NODE] = $config[Conf::URL_NODE];
        }
        if (in_array(Conf::SERVICE_NAME_NODE, $definedOptions) && !isset($options[Conf::SERVICE_NAME_NODE])) {
            $options[Conf::SERVICE_NAME_NODE] = $this->serviceName;
        }

        return $options;
    }

    /**
     * @param string $exporterKey
     * @return string
     */
    private function createExporterId(string $exporterKey): string
    {
        return sprintf('%s.%s', ServiceHelper::classToId(SpanExporters::NAMESPACE), $exporterKey);
    }

    /**
     * @param array $config
     * @return bool
     */
    private function isExporterClassConfiguration(array $config): bool
    {
        if (in_array($config[Conf::TYPE_NODE], Conf::EXPORTERS_NODE_VALUES, true)) {
            return true;
        }
        if ($config[Conf::TYPE_NODE] === Conf::CUSTOM_TYPE && isset($config[Conf::CLASS_NODE])) {
            return true;
        }

        return false;
    }

    /**
     * @param array $config
     * @return bool
     */
    private function isExporterReferenceConfiguration(array $config): bool
    {
        return $config[Conf::TYPE_NODE] === Conf::CUSTOM_TYPE && isset($config[Conf::ID_NODE]);
    }

    /**
     * @param string $exporterKey
     * @param array $config
     * @return string
     */
    private function registerExporterFactoryDefinition(string $exporterKey, array $config): string
    {
        $class = $this->resolveExporterClass($config);

        $id = self::concatId(
            ServiceHelper::classToId(SpanExporters::NAMESPACE) . '.' . self::FACTORY_SUFFIX,
            $exporterKey
        );
        $this->registerService(
            $id,
            $this->createExporterFactoryDefinition($class),
            false
        );

        return $id;
    }

    /**
     * @param string $exporterClass
     * @return Definition
     */
    private function createExporterFactoryDefinition(string $exporterClass): Definition
    {
        $definition = self::createDefinition(ExporterFactory::class);
        $definition->setArguments([$exporterClass]);

        return $definition;
    }

    /**
     * @param array $config
     * @return string
     */
    private function resolveExporterClass(array $config): string
    {
        if (in_array($config[Conf::TYPE_NODE], Conf::EXPORTERS_NODE_VALUES, true)) {
            return ConfigMappings::SPAN_EXPORTERS[
            $config[Conf::TYPE_NODE]
            ];
        }
        if ($config[Conf::TYPE_NODE] === Conf::CUSTOM_TYPE) {
            if (isset($config[Conf::CLASS_NODE])) {
                return $config[Conf::CLASS_NODE];
            }
        }

        throw new RuntimeException(
            sprintf(
                'Exporter must either be one of the following types: %s, %s ',
                Conf::CUSTOM_TYPE,
                implode(', ', Conf::EXPORTERS_NODE_VALUES)
            )
        );
    }

    /**
     * @param Definition $definition
     * @param string $processorType
     * @param string $exporterKey
     * @return string
     */
    private function registerSpanProcessor(Definition $definition, string $processorType, string $exporterKey): string
    {
        $id = sprintf(
            '%s.%s.%s',
            ServiceHelper::classToId(SpanProcessors::NAMESPACE),
            $processorType,
            $exporterKey
        );
        $this->getContainer()->setDefinition($id, $definition);

        $env  = $this->container->getParameter('kernel.environment');
        if (self::DEV_ENVIRONMENT  === $env) {
            $debugId = 'debug.open_telemetry.sdk.trace.span_processor.traceable';
            $this->getContainer()->getDefinition($debugId)
                ->setArgument(
                    0,
                    $this->createValidatedReference($id)
                )
            ;

            return $debugId;
        }

        return $id;
    }

    private function setStandardSpanProcessors(): void
    {
        $this->setSpanProcessorByClass(Conf::DEFAULT_TYPE, SpanProcessors::DEFAULT);
        foreach (Conf::PROCESSOR_NODE_VALUES as $type) {
            $this->setSpanProcessorByClass($type, ConfigMappings::SPAN_PROCESSORS[$type]);
        }
    }

    /**
     * @param string $processorType
     * @param string $class
     * @param array $args
     */
    private function setSpanProcessorByClass(string $processorType, string $class, array $args = []): void
    {
        $id = ServiceHelper::classToId($class);
        if ($this->getContainer()->hasDefinition($id)) {
            $this->setSpanProcessor($processorType, clone $this->getContainer()->getDefinition($id));

            return;
        }

        $this->setSpanProcessor($processorType, self::createDefinition($class, $args));
    }

    /**
     * @param string $processorType
     * @param Definition $definition
     */
    private function setSpanProcessor(string $processorType, Definition $definition): void
    {
        $this->processors[$processorType] = $definition;
    }

    private function getSpanProcessorDefinition($processorType): Definition
    {
        if (!isset($this->processors[$processorType])) {
            throw new RuntimeException(
                sprintf(
                    'Span processor "%s" reference not found.',
                    $processorType
                )
            );
        }

        return clone $this->processors[$processorType];
    }

    /**
     * @param array $config
     * @return Reference
     */
    private function createTraceSamplerReference(array $config): Reference
    {
        if (!isset($config[Conf::TYPE_NODE])) {
            throw new RuntimeException(
                'Trace sampler configuration needs "type" attribute.'
            );
        }

        $type = (string) $config[Conf::TYPE_NODE];

        if ($type === Conf::CUSTOM_TYPE) {
            return $this->createCustomServiceReference($config);
        }
        if ($type === Conf::ALWAYS_ON_SAMPLER || $type === Conf::ALWAYS_OFF_SAMPLER) {
            return $this->createReferenceFromClass(ConfigMappings::SAMPLERS[$type]);
        }
        if ($type === Conf::TRACE_ID_RATIO_SAMPLER) {
            return $this->createRatioSamplerReference($config);
        }

        throw new RuntimeException(
            'Invalid trace sampler type.'
        );
    }

    /**
     * @param array $config
     * @return Reference
     */
    private function createRatioSamplerReference(array $config): Reference
    {
        $type = Conf::TRACE_ID_RATIO_SAMPLER;
        $probability = $config[Conf::OPTIONS_NODE][Conf::PROBABILITY] ?? Conf::PROBABILITY_DEFAULT;
        $class = ConfigMappings::SAMPLERS[$type];
        $id = sprintf(
            '%s.%s',
            ServiceHelper::classToId($class),
            ServiceHelper::floatToString((float) $probability)
        );
        $definition = self::createDefinition($class, [$probability]);
        $this->getContainer()->setDefinition($id, $definition);

        return $this->createValidatedReference($id);
    }

    /**
     * @param array $config
     */
    private function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * @param ContainerBuilder $container
     */
    private function setContainer(ContainerBuilder $container): void
    {
        $this->container = $container;
    }

    /**
     * @return ContainerBuilder
     */
    private function getContainer(): ContainerBuilder
    {
        return $this->container;
    }

    private function loadDefaultConfig(): void
    {
        try {
            self::createPhpFileLoader($this->container)
                ->load(self::SDK_CONFIG_FILE);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not load config file: ' . self::SDK_CONFIG_FILE);
        }
    }

    private function loadDebugConfig(): void
    {
        $env = $this->container->getParameter('kernel.environment');
        if (self::DEV_ENVIRONMENT  !== $env) {
            return;
        }

        try {
            self::createPhpFileLoader($this->container)
                ->load(self::DEBUG_CONFIG_FILE);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not load config file: ' . self::DEBUG_CONFIG_FILE);
        }
    }

    /**
     * @param array $config
     * @param bool $classAlias
     * @param string|null $idSuffix
     * @return string
     */
    private function configureAndRegisterCustomService(
        array $config,
        bool $classAlias = true,
        ?string $idSuffix = null
    ): string {
        $id = ServiceHelper::classToId($config[Conf::CLASS_NODE]);
        if (is_string($idSuffix)) {
            $id = self::concatId($id, $idSuffix);
        }

        $this->registerService(
            $id,
            $this->configureCustomService($config),
            $classAlias
        );

        return $id;
    }

    /**
     * @param array $config
     * @return Definition
     */
    private function configureCustomService(array $config): Definition
    {
        return  self::createDefinition(
            $config[Conf::CLASS_NODE],
            $config[Conf::OPTIONS_NODE] ?? []
        );
    }

    /**
     * @param string $id
     * @param Definition $definition
     * @param bool $classAlias
     */
    private function registerService(string $id, Definition $definition, bool $classAlias = true): void
    {
        $this->getContainer()->setDefinition($id, $definition);
        if ($classAlias === true) {
            $className = $definition->getClass();
            if ($className === null) {
                throw new RuntimeException(sprintf(
                    'Cannot set class alias for id "%s". Definition has not class',
                    $id
                ));
            }
            $this->getContainer()->setAlias($className, $id);
        }
    }

    /**
     * @param array $config
     * @param bool $classAlias
     * @param string|null $idSuffix
     * @return Reference
     */
    private function createCustomServiceReference(
        array $config,
        bool $classAlias = true,
        ?string $idSuffix = null
    ): Reference {
        $id = null;
        if (isset($config[Conf::CLASS_NODE])) {
            $id = $this->configureAndRegisterCustomService($config, $classAlias, $idSuffix);
        }
        if (isset($config[Conf::ID_NODE])) {
            $id = self::normalizeStringReference($config[Conf::ID_NODE]);
        }
        if ($id === null) {
            throw new RuntimeException(
                'Custom service configuration needs "class" or "id" attribute.'
            );
        }

        return $this->createValidatedReference($id);
    }

    /**
     * @param string $id
     */
    private function validateId(string $id): void
    {
        if (!$this->getContainer()->has($id)) {
            throw new RuntimeException(
                sprintf('Service "%s" is not registered.', $id)
            );
        }
    }

    /**
     * @param string $id
     * @return Reference
     */
    private function createValidatedReference(string $id): Reference
    {
        $id = self::normalizeStringReference($id);
        $this->validateId($id);

        return self::createReference($id);
    }

    /**
     * @param string|null $class
     * @param array $arguments
     * @return Definition
     */
    private static function createDefinition(string $class = null, array $arguments = []): Definition
    {
        return new Definition($class, $arguments);
    }

    /**
     * @param string $id
     * @return Reference
     */
    private static function createReference(string $id): Reference
    {
        return new Reference($id);
    }

    /**
     * @param string $class
     * @return Reference
     */
    private static function createReferenceFromClass(string $class): Reference
    {
        return self::createReference(
            ServiceHelper::classToId($class)
        );
    }

    /**
     * @param string $id
     * @return string
     */
    private static function normalizeStringReference(string $id): string
    {
        return str_replace('@', '', $id);
    }

    /**
     * @param string $id
     * @param string|null $suffix
     * @return string
     */
    private static function concatId(string $id, ?string $suffix = null): string
    {
        if (is_string($suffix)) {
            $id = sprintf('%s.%s', $id, $suffix);
        }

        return $id;
    }

    /**
     * @param ContainerBuilder $container
     * @return PhpFileLoader
     */
    private static function createPhpFileLoader(ContainerBuilder $container): PhpFileLoader
    {
        return new PhpFileLoader(
            $container,
            self::createFileLocator()
        );
    }

    /**
     * @return FileLocator
     */
    private static function createFileLocator(): FileLocator
    {
        return new FileLocator();
    }

    /**
     * @param string $class
     * @return Definition
     */
    private function getDefinitionByClass(string $class): Definition
    {
        return $this->getContainer()->getDefinition(ServiceHelper::classToId($class));
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @param string $message
     * @param array $context
     */
    private function debug(string $message, array $context = []): void
    {
        if (!$this->logger instanceof LoggerInterface || !$this->isDebug()) {
            return;
        }

        $this->logger->debug($message, $context);
    }

    /**
     * @return bool
     */
    private function isDebug(): bool
    {
        return (bool) $this->getContainer()->getParameter('kernel.debug');
    }
}
