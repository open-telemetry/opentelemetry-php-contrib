<?php

declare(strict_types=1);

use Detectors\Aws\LambdaDetector;
use OpenTelemetry\Sdk\Resource\ResourceConstants;
use OpenTelemetry\Sdk\Resource\ResourceInfo;
use OpenTelemetry\Sdk\Trace\Attributes;
use PHPUnit\Framework\TestCase;

class LambdaDetectorTest extends TestCase
{
    private const LAMBDA_NAME_ENV = 'AWS_LAMBDA_FUNCTION_NAME';
    private const LAMBDA_VERSION_ENV = 'AWS_LAMBDA_FUNCTION_VERSION';
    private const AWS_REGION_ENV = 'AWS_REGION';
    private const CLOUD_PROVIDER = 'aws';

    private const LAMBDA_NAME_VAL = 'my-lambda';
    private const LAMBDA_VERSION_VAL = 'lambda-version';
    private const AWS_REGION_VAL = 'us-west-1';

    /**
     * @test
     */
    public function TestValidLambda()
    {
        putenv(self::LAMBDA_NAME_ENV . '=' . self::LAMBDA_NAME_VAL);
        putenv(self::LAMBDA_VERSION_ENV . '=' . self::LAMBDA_VERSION_VAL);
        putenv(self::AWS_REGION_ENV . '=' . self::AWS_REGION_VAL);

        $detector = new LambdaDetector();

        $this->assertEquals(ResourceInfo::create(
            new Attributes([
                    ResourceConstants::FAAS_NAME => self::LAMBDA_NAME_VAL,
                    ResourceConstants::FAAS_VERSION => self::LAMBDA_VERSION_VAL,
                    ResourceConstants::CLOUD_REGION => self::AWS_REGION_VAL,
                    ResourceConstants::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
                ])
        ), $detector->detect());

        //unset environment variable
        putenv(self::LAMBDA_NAME_ENV);
        putenv(self::LAMBDA_VERSION_ENV);
        putenv(self::AWS_REGION_ENV);
    }

    /**
     * @test
     */
    public function TestInvalidLambda()
    {
        $detector = new LambdaDetector();
        $this->assertEquals(ResourceInfo::emptyResource(), $detector->detect());
    }

    /**
     * @test
     */
    public function TestIncompleteLambda1()
    {
        putenv(self::LAMBDA_NAME_ENV . '=' . self::LAMBDA_NAME_VAL);

        $detector = new LambdaDetector();

        $this->assertEquals(ResourceInfo::create(
            new Attributes([
                    ResourceConstants::FAAS_NAME => self::LAMBDA_NAME_VAL,
                    ResourceConstants::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
                ])
        ), $detector->detect());

        //unset environment variable
        putenv(self::LAMBDA_NAME_ENV);
    }

    /**
     * @test
     */
    public function TestIncompleteLambda2()
    {
        putenv(self::LAMBDA_NAME_ENV . '=' . self::LAMBDA_NAME_VAL);
        putenv(self::AWS_REGION_ENV . '=' . self::AWS_REGION_VAL);

        $detector = new LambdaDetector();

        $this->assertEquals(ResourceInfo::create(
            new Attributes([
                    ResourceConstants::FAAS_NAME => self::LAMBDA_NAME_VAL,
                    ResourceConstants::CLOUD_REGION => self::AWS_REGION_VAL,
                    ResourceConstants::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
                ])
        ), $detector->detect());

        //unset environment variable
        putenv(self::LAMBDA_NAME_ENV);
        putenv(self::AWS_REGION_ENV);
    }
}
