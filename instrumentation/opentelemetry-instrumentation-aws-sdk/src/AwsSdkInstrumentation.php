<?php

use Opentelemetry\API\Common\Instrumentation\InstrumentationInterface;
use Opentelemetry\API\Common\Instrumentation\InstrumentationTrait;


class AwsSdkInstrumentation implements InstrumentationInteface
{

    use InstrumentationTrait;

    private $instrumented;

    public function getName(): string
    {
        return 'AWS SDK Instrumentation';
    }

    public function getVersion(): ?string
    {
        return '0.0.1';
    }

    public function getSchemaUrl(): ?string
    {
        return true;
    }

    public function init(): bool
    {
        return false;
    }

    function __construct()
    {
        $this->instrumented = false;
    }

    function activate()
    {
        echo "activating";
    }
}
