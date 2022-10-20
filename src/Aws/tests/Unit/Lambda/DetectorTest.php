<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Aws\Unit\Lambda;

use OpenTelemetry\Aws\Lambda\Detector;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SemConv\ResourceAttributes;
use PHPUnit\Framework\TestCase;

class DetectorTest extends TestCase
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

        $detector = new Detector();

        $this->assertEquals(ResourceInfo::create(
            Attributes::create([
                ResourceAttributes::FAAS_NAME => self::LAMBDA_NAME_VAL,
                ResourceAttributes::FAAS_VERSION => self::LAMBDA_VERSION_VAL,
                ResourceAttributes::CLOUD_REGION => self::AWS_REGION_VAL,
                ResourceAttributes::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
            ])
        ), $detector->getResource());

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
        $detector = new Detector();
        $this->assertEquals(ResourceInfoFactory::emptyResource(), $detector->getResource());
    }

    /**
     * @test
     */
    public function TestIncompleteLambda1()
    {
        putenv(self::LAMBDA_NAME_ENV . '=' . self::LAMBDA_NAME_VAL);

        $detector = new Detector();

        $this->assertEquals(ResourceInfo::create(
            Attributes::create([
                ResourceAttributes::FAAS_NAME => self::LAMBDA_NAME_VAL,
                ResourceAttributes::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
                ])
        ), $detector->getResource());

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

        $detector = new Detector();

        $this->assertEquals(ResourceInfo::create(
            Attributes::create([
                ResourceAttributes::FAAS_NAME => self::LAMBDA_NAME_VAL,
                ResourceAttributes::CLOUD_REGION => self::AWS_REGION_VAL,
                ResourceAttributes::CLOUD_PROVIDER => self::CLOUD_PROVIDER,
            ])
        ), $detector->getResource());

        //unset environment variable
        putenv(self::LAMBDA_NAME_ENV);
        putenv(self::AWS_REGION_ENV);
    }
}
