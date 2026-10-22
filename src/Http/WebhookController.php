<?php

declare(strict_types=1);

namespace SMTPfast\Laravel\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use SMTPfast\Laravel\Events\WebhookReceived;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Dispatches a verified webhook as a WebhookReceived event.
 */
final class WebhookController
{
    public function __invoke(Request $request): Response
    {
        $event = json_decode($request->getContent());
        if (!$event instanceof \stdClass) {
            throw new HttpException(400, 'The webhook body is not a JSON object.');
        }
        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), true);
        Event::dispatch(new WebhookReceived($payload));

        return new Response('', 204);
    }
}
