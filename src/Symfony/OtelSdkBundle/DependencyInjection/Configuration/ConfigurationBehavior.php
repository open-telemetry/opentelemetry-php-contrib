<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection\Configuration;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

trait ConfigurationBehavior
{
    private ?LoggerInterface $logger = null;
    private bool $debug = false;

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    private function debug(string $message, array $context = []): void
    {
        if (!$this->isDebug()) {
            return;
        }

        $this->getLogger()->debug($message, $context);
    }

    private function startDebug(): void
    {
        $this->debug('Start building config tree in ' . __CLASS__);
    }

    private function endDebug(): void
    {
        $this->debug('Finished building config tree in ' . __CLASS__);
    }

    abstract public static function getName(): string;

    abstract public static function canBeDisabled(): bool;
}
