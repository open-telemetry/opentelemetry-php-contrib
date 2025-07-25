<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PDO;

use OpenTelemetry\SDK\Common\Configuration\Configuration;

class SqlCommentPropagator implements SqlPropagatorInterface
{
    public static function isPrepend(): bool
    {
        if (class_exists('OpenTelemetry\SDK\Common\Configuration\Configuration') && Configuration::getBoolean('OTEL_PHP_INSTRUMENTATION_PDO_SQL_COMMENTER_PREPEND', false)) {
            return true;
        }

        return filter_var(get_cfg_var('otel.instrumentation.pdo.sql_commenter.prepend'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    public static function inject(string $query, array $comments): string
    {
        $query = trim($query);
        if (self::isPrepend()) {
            return Utils::formatComments(array_filter($comments)) . $query;
        }
        $hasSemicolon = $query !== '' && $query[strlen($query) - 1] === ';';
        $query = rtrim($query, ';');

        return $query . Utils::formatComments(array_filter($comments)) . ($hasSemicolon ? ';' : '');
    }
}
