<?php

declare(strict_types=1);

namespace SMTPfast\Laravel\Tests;

use SMTPfast\Http\Response;
use SMTPfast\Http\Transport;

/**
 * Stands in for the SMTPfast API: records each request and answers from a
 * list, then with {"id":"em_123"}.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $calls = [];

    /**
     * @param list<Response> $replies
     */
    public function __construct(public array $replies = [])
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        return array_shift($this->replies) ?? new Response(200, ['Content-Type' => 'application/json'], '{"id":"em_123"}');
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(int $i = 0): array
    {
        return json_decode((string) $this->calls[$i]['body'], true, 512, JSON_THROW_ON_ERROR);
    }
}
