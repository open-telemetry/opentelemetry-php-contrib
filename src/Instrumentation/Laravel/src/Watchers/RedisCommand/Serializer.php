<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\RedisCommand;

/**
 * @see https://github.com/open-telemetry/opentelemetry-js-contrib/blob/main/packages/opentelemetry-redis-common/src/index.ts
 */
class Serializer
{
    /**
     * List of regexes and the number of arguments that should be serialized for matching commands.
     * For example, HSET should serialize which key and field it's operating on, but not its value.
     * Setting the subset to -1 will serialize all arguments.
     * Commands without a match will have their first argument serialized.
     *
     * Refer to https://redis.io/commands/ for the full list.
     */
    private const SERIALIZATION_SUBSETS = [
        [
            'regex' => '/^ECHO/i',
            'args' => 0,
        ],
        [
            'regex' => '/^(LPUSH|MSET|PFA|PUBLISH|RPUSH|SADD|SET|SPUBLISH|XADD|ZADD)/i',
            'args' => 1,
        ],
        [
            'regex' => '/^(HSET|HMSET|LSET|LINSERT)/i',
            'args' => 2,
        ],
        [
            'regex' => '/^(ACL|BIT|B[LRZ]|CLIENT|CLUSTER|CONFIG|COMMAND|DECR|DEL|EVAL|EX|FUNCTION|GEO|GET|HINCR|HMGET|HSCAN|INCR|L[TRLM]|MEMORY|P[EFISTU]|RPOP|S[CDIMORSU]|XACK|X[CDGILPRT]|Z[CDILMPRS])/i',
            'args' => -1,
        ],
    ];

    /**
     * Given the redis command name and arguments, return a combination of the
     * command name + the allowed arguments according to `SERIALIZATION_SUBSETS`.
     *
     * @param string $command The redis command name
     * @param array $params The redis command arguments
     * @return string A combination of the command name + args according to `SERIALIZATION_SUBSETS`.
     */
    public static function serializeCommand(string $command, array $params): string
    {
        if (count($params) === 0) {
            return $command;
        }

        $paramsToSerializeNum = 0;

        // Find the number of arguments to serialize for the given command
        foreach (self::SERIALIZATION_SUBSETS as $subset) {
            if (preg_match($subset['regex'], $command)) {
                $paramsToSerializeNum = $subset['args'];

                break;
            }
        }

        // Serialize the allowed number of arguments
        $paramsToSerialize = ($paramsToSerializeNum >= 0) ? array_slice($params, 0, $paramsToSerializeNum) : $params;

        // If there are more arguments than serialized, add a placeholder
        if (count($params) > count($paramsToSerialize)) {
            $paramsToSerialize[] = '[' . (count($params) - $paramsToSerializeNum) . ' other arguments]';
        }

        // In some cases (for example when using LUA scripts) arrays are valid parameters
        $paramsToSerialize = array_map(function($param) { return is_array($param) ? json_encode($param) : $param; }, $paramsToSerialize);
        
        return $command . ' ' . implode(' ', $paramsToSerialize);
    }
}
