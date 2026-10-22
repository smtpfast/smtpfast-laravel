<?php

declare(strict_types=1);

namespace SMTPfast\Laravel\Tests;

use SMTPfast\Client;
use SMTPfast\Exception\SMTPfastException;
use SMTPfast\Laravel\Facades\SMTPfast;
use SMTPfast\Resources\EmailsResource;

final class ServiceProviderTest extends TestCase
{
    public function testTheMailerIsDefined(): void
    {
        $this->assertSame(['transport' => 'smtpfast'], config('mail.mailers.smtpfast'));
    }

    public function testTheClientIsASingletonFromConfig(): void
    {
        $this->app->forgetInstance(Client::class);
        $client = $this->app->make(Client::class);
        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame($client, $this->app->make(Client::class));
        $this->assertSame($client, $this->app->make('smtpfast'));
    }

    public function testTheKeyCanComeFromServicesConfig(): void
    {
        $this->app->forgetInstance(Client::class);
        config(['smtpfast.api_key' => null, 'services.smtpfast.key' => 'sf_from_services']);
        $this->assertInstanceOf(Client::class, $this->app->make(Client::class));
    }

    public function testNoKeyIsAClearError(): void
    {
        $this->app->forgetInstance(Client::class);
        config(['smtpfast.api_key' => null]);
        $this->expectException(SMTPfastException::class);
        $this->expectExceptionMessage('No API key');
        $this->withoutEnv('SMTPFAST_API_KEY', fn () => $this->app->make(Client::class));
    }

    public function testAPartialConfigFileKeepsTheDefaults(): void
    {
        config(['smtpfast' => ['webhook' => ['path' => 'hooks/smtpfast']]]);
        (new \SMTPfast\Laravel\SMTPfastServiceProvider($this->app))->register();

        $this->assertSame('hooks/smtpfast', config('smtpfast.webhook.path'));
        $this->assertArrayHasKey('secret', config('smtpfast.webhook'));
        $this->assertSame(2, config('smtpfast.max_retries'));
    }

    public function testFacade(): void
    {
        $this->assertInstanceOf(EmailsResource::class, SMTPfast::emails());
        $this->assertSame($this->app->make(Client::class)->emails, SMTPfast::emails());
        SMTPfast::emails()->send(['from' => 'a@yourapp.dev', 'to' => 'b@inbox.dev', 'subject' => 'Hi', 'text' => 'Hello']);
        $this->assertCount(1, $this->api->calls);
    }

    public function testTheFacadeDocumentsEveryGroup(): void
    {
        $doc = (string) (new \ReflectionClass(SMTPfast::class))->getDocComment();
        foreach ((new \ReflectionClass(Client::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $type = (string) $property->getType();
            $this->assertStringContainsString("@method static \\{$type} {$property->getName()}()", $doc);
        }
    }

    private function withoutEnv(string $name, \Closure $run): mixed
    {
        $saved = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
        try {
            return $run();
        } finally {
            [$env, $server, $process] = $saved;
            if ($env !== null) {
                $_ENV[$name] = $env;
            }
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
            if ($process !== false) {
                putenv("$name=$process");
            }
        }
    }
}
