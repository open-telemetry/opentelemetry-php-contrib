<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\Factory;

use ReflectionClass;
use Symfony\Component\OptionsResolver\OptionsResolver;

interface GenericFactoryInterface
{

    /**
     * @param string $buildClass
     * @param OptionsResolver|null $resolver
     * @return self
     */
    public static function create(string $buildClass, ?OptionsResolver $resolver = null): self;

    /**
     * @param array $options
     * @return object
     */
    public function build(array $options = []): object;

    /**
     * @param string $option
     * @param mixed $value
     * @return $this
     */
    public function setDefault(string $option, $value): self;

    /**
     * @param array $options
     * @return $this
     */
    public function setDefaults(array $options): self;

    /**
     * @return array
     */
    public function getOptions(): array;

    /**
     * @return array
     */
    public function getRequiredOptions(): array;

    /**
     * @return array
     */
    public function getDefaults(): array;

    /**
     * @return ReflectionClass
     */
    public function getReflectionClass(): ReflectionClass;

    /**
     * @return string
     */
    public function getClass(): string;

    /**
     * @return OptionsResolver
     */
    public function getOptionsResolver(): OptionsResolver;
}
