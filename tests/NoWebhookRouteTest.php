<?php

declare(strict_types=1);

namespace SMTPfast\Laravel\Tests;

use Illuminate\Support\Facades\Route;

final class NoWebhookRouteTest extends TestCase
{
    public function testNoRouteWithoutAPath(): void
    {
        $this->assertFalse(Route::has('smtpfast.webhook'));
        $this->call('POST', '/smtpfast/webhook')->assertNotFound();
    }
}
