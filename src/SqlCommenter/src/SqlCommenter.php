<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\SqlCommenter;

use OpenTelemetry\API\Globals;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

class SqlCommenter
{
    private static ?self $instance = null;

    public function __construct(private readonly ?TextMapPropagatorInterface $contextPropagator = null)
    {
    }

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self((new ContextPropagatorFactory())->create());
        }

        return self::$instance;
    }

    public function isAttributeEnabled(): bool
    {
        if (class_exists('OpenTelemetry\\SDK\\Common\\Configuration\\Configuration') && \OpenTelemetry\SDK\Common\Configuration\Configuration::getBoolean('OTEL_PHP_SQLCOMMENTER_ATTRIBUTE', false)) {
            return true;
        }

        return filter_var(get_cfg_var('otel.sqlcommenter.attribute'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    public function isPrepend(): bool
    {
        if (class_exists('OpenTelemetry\SDK\Common\Configuration\Configuration') && \OpenTelemetry\SDK\Common\Configuration\Configuration::getBoolean('OTEL_PHP_SQLCOMMENTER_PREPEND', false)) {
            return true;
        }

        return filter_var(get_cfg_var('otel.sqlcommenter.prepend'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    public function inject(string $query): string
    {
        $comments = [];
        if ($this->contextPropagator !== null) {
            $this->contextPropagator->inject($comments);
        } else {
            Globals::propagator()->inject($comments);
        }
        $query = trim($query);
        if ($this->isPrepend()) {
            return Utils::formatComments(array_filter($comments)) . $query;
        }
        $hasSemicolon = $query !== '' && $query[strlen($query) - 1] === ';';
        $query = rtrim($query, ';');

        return $query . Utils::formatComments(array_filter($comments)) . ($hasSemicolon ? ';' : '');
    }
}
