<?php

declare(strict_types=1);

namespace SMTPfast\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Mail\MailManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use SMTPfast\Client;
use SMTPfast\Laravel\Http\VerifyWebhookSignature;
use SMTPfast\Laravel\Http\WebhookController;

final class SMTPfastServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/smtpfast.php', 'smtpfast');

        $this->app->singleton(Client::class, fn ($app): Client => self::client($app->make('config')));
        $this->app->alias(Client::class, 'smtpfast');

        // MAIL_MAILER=smtpfast works without a mailer entry in config/mail.php.
        $config = $this->app->make('config');
        if (!$config->has('mail.mailers.smtpfast')) {
            $config->set('mail.mailers.smtpfast', ['transport' => 'smtpfast']);
        }
    }

    public function boot(): void
    {
        $this->callAfterResolving('mail.manager', function (MailManager $manager): void {
            $manager->extend('smtpfast', function (array $config = []): SMTPfastTransport {
                // A mailer with its own key gets its own client.
                $client = isset($config['key']) && $config['key'] !== ''
                    ? self::client($this->app->make('config'), (string) $config['key'])
                    : $this->app->make(Client::class);
                $category = $config['category'] ?? $this->app->make('config')->get('smtpfast.category');

                return new SMTPfastTransport($client, is_string($category) && $category !== '' ? $category : null);
            });
        });

        $router = $this->app->make('router');
        $router->aliasMiddleware('smtpfast.webhook', VerifyWebhookSignature::class);
        $path = $this->app->make('config')->get('smtpfast.webhook.path');
        $routesCached = $this->app instanceof CachesRoutes && $this->app->routesAreCached();
        if (is_string($path) && trim($path, '/') !== '' && !$routesCached) {
            $router->post(trim($path, '/'), WebhookController::class)
                ->middleware(VerifyWebhookSignature::class)
                ->name('smtpfast.webhook');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/smtpfast.php' => $this->app->configPath('smtpfast.php')], 'smtpfast-config');
        }
    }

    private static function client(Repository $config, ?string $key = null): Client
    {
        $key ??= $config->get('smtpfast.api_key') ?: $config->get('services.smtpfast.key');

        return new Client(
            apiKey: is_string($key) && $key !== '' ? $key : null,
            baseUrl: $config->get('smtpfast.base_url') ?: null,
            maxRetries: (int) $config->get('smtpfast.max_retries', 2),
            timeout: (float) $config->get('smtpfast.timeout', 60),
        );
    }
}
