<?php

declare(strict_types=1);

namespace SMTPfast\Laravel\Events;

/**
 * A webhook from SMTPfast, after its signature was checked. $payload is the
 * decoded body: type, data and the other fields of the event.
 */
final class WebhookReceived
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public readonly array $payload)
    {
    }
}
