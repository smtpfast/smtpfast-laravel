<?php

declare(strict_types=1);

namespace SMTPfast\Laravel\Tests;

use Illuminate\Support\Facades\Mail;
use SMTPfast\Client;
use SMTPfast\Laravel\SMTPfastServiceProvider;
use SMTPfast\Laravel\Tests\Fixtures\LiveCheck;

/**
 * Sends one real email through the smtpfast mailer, then reads it back with
 * the PHP SDK. It goes only to the Amazon SES mailbox simulator, never to a
 * real inbox. Runs only with SMTPFAST_API_KEY (email:send and email:read)
 * and SMTPFAST_TEST_FROM (a sender on a verified domain) set.
 */
final class LiveTest extends \Orchestra\Testbench\TestCase
{
    private const TO = 'success@simulator.amazonses.com';
    private const BCC = 'success+bcc@simulator.amazonses.com';

    protected function getPackageProviders($app): array
    {
        return [SMTPfastServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('smtpfast.api_key', self::env('SMTPFAST_API_KEY'));
        $app['config']->set('mail.default', 'smtpfast');
        $app['config']->set('mail.from', ['address' => self::env('SMTPFAST_TEST_FROM'), 'name' => 'smtpfast-laravel live test']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (self::env('SMTPFAST_API_KEY') === null || self::env('SMTPFAST_TEST_FROM') === null) {
            $this->markTestSkipped('SMTPFAST_API_KEY and SMTPFAST_TEST_FROM are not both set');
        }
    }

    public function testSendsOneEmailAndReadsItBack(): void
    {
        $subject = 'smtpfast-laravel live test ' . gmdate('Y-m-d H:i:s') . ' ' . bin2hex(random_bytes(3));

        $sent = Mail::mailer('smtpfast')->to(self::TO)->bcc(self::BCC)->send(new LiveCheck($subject));

        $id = $sent?->getMessageId();
        $this->assertIsString($id);
        $this->assertNotSame('', $id);
        fwrite(STDERR, "\nSMTPfast email id: $id\n");

        $email = $this->app->make(Client::class)->emails->get($id);
        $this->assertSame($id, $email['id']);
        $this->assertSame([self::TO], self::addresses($email['to']));
        $this->assertNotContains(self::BCC, self::addresses($email['to']));
        $this->assertNotContains(self::BCC, self::addresses($email['cc']));
        $this->assertSame([self::BCC], self::addresses($email['bcc']));
        $this->assertSame($subject, $email['subject']);
        $this->assertSame('transactional', $email['category']);
        fwrite(STDERR, sprintf("status %s, to %s, bcc %s\n", $email['status'], implode(',', $email['to']), implode(',', $email['bcc'])));
    }

    /**
     * Bare addresses in lower case, from values that may carry a display name.
     *
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function addresses(array $values): array
    {
        return array_map(
            fn (string $value): string => strtolower(preg_match('/<([^<>]+)>\s*$/', $value, $m) === 1 ? trim($m[1]) : trim($value)),
            $values,
        );
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
