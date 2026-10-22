<?php

declare(strict_types=1);

namespace SMTPfast\Laravel\Http;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use SMTPfast\Webhooks;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Lets a request through only when its X-SMTPfast-Signature matches the body
 * and smtpfast.webhook.secret. Use it on your own route as 'smtpfast.webhook'.
 */
final class VerifyWebhookSignature
{
    public function __construct(private readonly Repository $config)
    {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $this->config->get('smtpfast.webhook.secret');
        if (!is_string($secret) || $secret === '') {
            throw new \RuntimeException('Set SMTPFAST_WEBHOOK_SECRET (smtpfast.webhook.secret) to receive SMTPfast webhooks.');
        }
        if (!Webhooks::verifySignature($request->getContent(), $request->header(Webhooks::SIGNATURE_HEADER), $secret)) {
            throw new HttpException(401, 'Invalid SMTPfast webhook signature.');
        }

        return $next($request);
    }
}
