<?php

declare(strict_types=1);

namespace SMTPfast\Laravel\Tests;

use SMTPfast\Client;
use SMTPfast\Laravel\SMTPfastServiceProvider;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected FakeTransport $api;

    protected function getPackageProviders($app): array
    {
        return [SMTPfastServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('smtpfast.api_key', 'sf_test_key');
        $app['config']->set('mail.default', 'smtpfast');
        $app['config']->set('mail.from', ['address' => 'hello@yourapp.dev', 'name' => 'Acme']);
        $app['config']->set('view.paths', [__DIR__ . '/Fixtures/views']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = new FakeTransport();
        $this->app->instance(Client::class, new Client('sf_test_key', maxRetries: 0, transport: $this->api));
    }
}
