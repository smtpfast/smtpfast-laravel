<?php

declare(strict_types=1);

namespace SMTPfast\Laravel\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use SMTPfast\Laravel\Events\WebhookReceived;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('smtpfast.webhook.secret', self::SECRET);
        $app['config']->set('smtpfast.webhook.path', '/smtpfast/webhook');
    }

    protected function defineRoutes($router): void
    {
        $router->post('/my-hook', fn () => 'ok')->middleware('smtpfast.webhook');
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function deliver(string $uri, string $payload, ?string $signature)
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_X_SMTPFAST_SIGNATURE'] = $signature;
        }

        return $this->call('POST', $uri, [], [], [], $server, $payload);
    }

    public function testValidSignatureDispatchesTheEvent(): void
    {
        Event::fake([WebhookReceived::class]);
        $payload = '{"type":"email.delivered","data":{"id":"em_1"}}';

        $this->deliver('/smtpfast/webhook', $payload, hash_hmac('sha256', $payload, self::SECRET))->assertNoContent();

        $this->assertTrue(Route::has('smtpfast.webhook'));
        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $e) => $e->payload['type'] === 'email.delivered' && $e->payload['data']['id'] === 'em_1');
    }

    public function testBadSignatureIsRefused(): void
    {
        Event::fake([WebhookReceived::class]);
        $payload = '{"type":"email.delivered"}';

        $this->deliver('/smtpfast/webhook', $payload, hash_hmac('sha256', $payload . ' ', self::SECRET))->assertStatus(401);
        $this->deliver('/smtpfast/webhook', $payload, null)->assertStatus(401);
        $this->deliver('/smtpfast/webhook', $payload, hash_hmac('sha256', $payload, 'another secret'))->assertStatus(401);

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function testBodyThatIsNotAnObject(): void
    {
        $this->deliver('/smtpfast/webhook', '"hello"', hash_hmac('sha256', '"hello"', self::SECRET))->assertStatus(400);
    }

    public function testMiddlewareOnYourOwnRoute(): void
    {
        $payload = '{"type":"email.bounced"}';
        $this->deliver('/my-hook', $payload, hash_hmac('sha256', $payload, self::SECRET))->assertOk()->assertSee('ok');
        $this->deliver('/my-hook', $payload, 'bad')->assertStatus(401);
    }
}
