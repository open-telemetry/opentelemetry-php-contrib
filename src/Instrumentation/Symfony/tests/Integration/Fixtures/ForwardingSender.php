<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\Fixtures;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Minimal decorator handing every envelope over to the wrapped sender, as a
 * transport does with its sender (AmqpTransport and AmqpSender).
 */
final class ForwardingSender implements SenderInterface
{
    public function __construct(
        private SenderInterface $sender,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedReturnValue */
    public function send(Envelope $envelope): Envelope
    {
        return $this->sender->send($envelope);
    }
}
