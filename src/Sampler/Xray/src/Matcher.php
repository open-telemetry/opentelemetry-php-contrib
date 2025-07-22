<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Sampler\Xray;

class Matcher
{
    private static array $xrayCloudPlatform = [
        'aws_ec2' => 'AWS::EC2::Instance',
        'aws_ecs' => 'AWS::ECS::Container',
        'aws_eks' => 'AWS::EKS::Container',
        'aws_elastic_beanstalk' => 'AWS::ElasticBeanstalk::Environment',
        'aws_lambda' => 'AWS::Lambda::Function',
    ];

    /**
     * Check that every rule attribute key/value is present and equal in the span tags.
     */
    public static function attributeMatch(?array $tags, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            if ($tags === null || !array_key_exists($key, $tags)) {
                return false;
            }
            if ((string) $tags[$key] !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Wildcard match (‘*’ → any chars). Null value only matches if pattern is '*' or empty.
     */
    public static function wildcardMatch(?string $value, string $pattern): bool
    {
        if ($pattern === '' || $pattern === '*') {
            return true;
        }
        if ($value === null) {
            return false;
        }
        // escape regex, then replace \* with .*
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';

        return (bool) preg_match($regex, $value);
    }

    /**
     * Map OpenTelemetry cloud.platform values to X-Ray service type strings.
     */
    public static function getXRayCloudPlatform(string $platform): string
    {
        return self::$xrayCloudPlatform[$platform] ?? '';
    }
}
