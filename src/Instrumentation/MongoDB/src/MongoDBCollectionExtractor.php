<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\MongoDB;

final class MongoDBCollectionExtractor
{
    public static function extract(object $command): ?string
    {
        /** @var mixed $maybeCollectionName */
        $maybeCollectionName = array_values((array) $command)[0] ?? null;

        // Special case for getMore
        /** @var mixed $maybeCollectionName */
        $maybeCollectionName = is_array($maybeCollectionName)
            ? $maybeCollectionName['collection'] ?? null
            : $maybeCollectionName;

        return is_string($maybeCollectionName) ? $maybeCollectionName : null;
    }
}
