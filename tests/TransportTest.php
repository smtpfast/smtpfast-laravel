<?php

declare(strict_types=1);

namespace SMTPfast\Laravel\Tests;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use SMTPfast\Http\Response;
use SMTPfast\Laravel\SMTPfastTransport;
use SMTPfast\Laravel\Tests\Fixtures\OrderShipped;
use SMTPfast\Laravel\Tests\Fixtures\PasswordReset;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address as SymfonyAddress;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

final class TransportTest extends TestCase
{
    public function testMailableFieldMapping(): void
    {
        $sent = Mail::to([['email' => 'ann@inbox.dev', 'name' => 'Ann Lee']])
            ->cc('cc@inbox.dev')
            ->bcc('bcc@inbox.dev')
            ->send(new OrderShipped());

        $this->assertSame('em_123', $sent?->getMessageId());
        $this->assertCount(1, $this->api->calls);
        $call = $this->api->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://smtpfa.st/api/v1/emails', $call['url']);
        $this->assertSame('Bearer sf_test_key', $call['headers']['Authorization']);

        $payload = $this->api->payload();
        $this->assertSame('"Acme Orders" <orders@yourapp.dev>', $payload['from']);
        $this->assertSame(['"Ann Lee" <ann@inbox.dev>'], $payload['to']);
        $this->assertSame(['cc@inbox.dev'], $payload['cc']);
        $this->assertSame(['bcc@inbox.dev'], $payload['bcc']);
        $this->assertSame(['"Acme Help" <help@yourapp.dev>'], $payload['reply_to']);
        $this->assertSame('Your order shipped', $payload['subject']);
        $this->assertSame('Order 1234 is on its way.', trim($payload['text']));
        $this->assertSame('transactional', $payload['category']);
        $this->assertSame([['name' => 'order_id', 'value' => '1234']], $payload['tags']);
        // Laravel's tag() header stays a header, as with Laravel's other API mail drivers.
        $this->assertEquals(['X-Entity-Ref-ID' => 'abc-123', 'X-Tag' => 'shipping'], $payload['headers']);

        $attachments = array_column($payload['attachments'], null, 'filename');
        $this->assertCount(2, $attachments);
        [$logo, $csv] = [$attachments['logo.png'], $attachments['order.csv']];
        $this->assertSame(['filename' => 'order.csv', 'content' => base64_encode("sku,qty\nlamp,1\n"), 'content_type' => 'text/csv'], $csv);
        $this->assertSame('logo.png', $logo['filename']);
        $this->assertSame(base64_encode('PNGDATA'), $logo['content']);
        $this->assertSame('image/png', $logo['content_type']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}@symfony$/', $logo['content_id']);
        $this->assertStringContainsString('<img src="cid:' . $logo['content_id'] . '"', $payload['html']);
        $this->assertStringContainsString('Order 1234 is on its way.', $payload['html']);
    }

    /**
     * Sends through the transport with an envelope of its own, the way
     * Symfony's Envelope::setRecipients() makes it: new Address objects, no names.
     *
     * @param list<string> $envelopeRecipients
     *
     * @return array<string, mixed>
     */
    private function sendWithEnvelope(Email $email, array $envelopeRecipients): array
    {
        $transport = new SMTPfastTransport(new \SMTPfast\Client('sf_test_key', maxRetries: 0, transport: $this->api));
        $envelope = new Envelope(new SymfonyAddress('orders@yourapp.dev'), array_map(fn (string $a) => new SymfonyAddress($a), $envelopeRecipients));
        $this->assertSame('em_123', $transport->send($email, $envelope)?->getMessageId());

        return $this->api->payload(count($this->api->calls) - 1);
    }

    private static function email(): Email
    {
        return (new Email())->from('orders@yourapp.dev')->subject('Hi')->text('Hello')
            ->to(new SymfonyAddress('ann@inbox.dev', 'Ann'))
            ->cc('cc@inbox.dev')
            ->bcc('secret@inbox.dev');
    }

    public function testBccNeverAppearsInToOrCc(): void
    {
        $payload = $this->sendWithEnvelope(self::email(), ['ann@inbox.dev', 'cc@inbox.dev', 'secret@inbox.dev']);

        $this->assertSame(['"Ann" <ann@inbox.dev>'], $payload['to']);
        $this->assertSame(['cc@inbox.dev'], $payload['cc']);
        $this->assertSame(['secret@inbox.dev'], $payload['bcc']);

        Mail::raw('Hello', fn (Message $m) => $m->to('ann@inbox.dev')->cc('cc@inbox.dev')->bcc(['secret@inbox.dev', 'secret2@inbox.dev'])->subject('Hi'));
        $payload = $this->api->payload(1);
        $this->assertSame(['ann@inbox.dev'], $payload['to']);
        $this->assertSame(['cc@inbox.dev'], $payload['cc']);
        $this->assertSame(['secret@inbox.dev', 'secret2@inbox.dev'], $payload['bcc']);
    }

    public function testEnvelopeOnlyRecipientGoesToBcc(): void
    {
        $payload = $this->sendWithEnvelope(self::email(), ['ann@inbox.dev', 'cc@inbox.dev', 'secret@inbox.dev', 'audit@inbox.dev']);

        $this->assertSame(['"Ann" <ann@inbox.dev>'], $payload['to']);
        $this->assertSame(['cc@inbox.dev'], $payload['cc']);
        $this->assertSame(['secret@inbox.dev', 'audit@inbox.dev'], $payload['bcc']);
    }

    public function testRecipientComparisonIgnoresCase(): void
    {
        $payload = $this->sendWithEnvelope(self::email(), ['ANN@Inbox.Dev', 'Cc@inbox.dev', 'SECRET@INBOX.DEV']);

        $this->assertSame(['"Ann" <ann@inbox.dev>'], $payload['to']);
        $this->assertSame(['cc@inbox.dev'], $payload['cc']);
        $this->assertSame(['secret@inbox.dev'], $payload['bcc']);
    }

    public function testSendsAreIdempotentAcrossRetries(): void
    {
        $api = new FakeTransport([new Response(503, ['Retry-After' => '0'], '{"error":"busy"}')]);
        $this->app->instance(\SMTPfast\Client::class, new \SMTPfast\Client('sf_test_key', maxRetries: 1, transport: $api));

        $sent = Mail::raw('Hello', fn (Message $m) => $m->to('ann@inbox.dev')->subject('Hi'));

        $this->assertSame('em_123', $sent?->getMessageId());
        $this->assertCount(2, $api->calls);
        $key = $api->calls[0]['headers']['Idempotency-Key'] ?? '';
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $key);
        $this->assertSame($key, $api->calls[1]['headers']['Idempotency-Key'] ?? '');
    }

    public function testNotificationSendsThroughTheApi(): void
    {
        Notification::route('mail', 'ann@inbox.dev')->notify(new PasswordReset());

        $payload = $this->api->payload();
        $this->assertSame('"Acme" <hello@yourapp.dev>', $payload['from']);
        $this->assertSame(['ann@inbox.dev'], $payload['to']);
        $this->assertSame('Reset your password', $payload['subject']);
        $this->assertStringContainsString('Someone asked to reset your password.', $payload['html']);
        $this->assertStringContainsString('https://yourapp.dev/reset/abc', $payload['text']);
        $this->assertArrayNotHasKey('category', $payload);
    }

    public function testInlinePartsReferencedByName(): void
    {
        Mail::html('<img src="cid:my logo.png"><img src=\'cid:chart\'>', function (Message $m): void {
            $m->to('ann@inbox.dev')->subject('Report');
            $m->getSymfonyMessage()->addPart((new DataPart('PNG', 'my logo.png', 'image/png'))->asInline());
            // An attachment that the HTML shows becomes inline, as in a MIME message built by Symfony.
            $m->getSymfonyMessage()->addPart(new DataPart('SVG', 'chart', 'image/svg+xml'));
            $m->getSymfonyMessage()->addPart(new DataPart('PDF', 'report.pdf', 'application/pdf'));
        });

        $payload = $this->api->payload();
        [$logo, $chart, $pdf] = $payload['attachments'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}@symfony$/', $logo['content_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}@symfony$/', $chart['content_id']);
        $this->assertArrayNotHasKey('content_id', $pdf);
        $this->assertSame(
            '<img src="cid:' . $logo['content_id'] . '"><img src=\'cid:' . $chart['content_id'] . '\'>',
            $payload['html'],
        );
    }

    public function testInlinePartWithExplicitContentId(): void
    {
        Mail::html('<img src="cid:logo@yourapp.dev">', function (Message $m): void {
            $m->to('ann@inbox.dev')->subject('Logo');
            $m->getSymfonyMessage()->addPart((new DataPart('PNG', 'logo.png', 'image/png'))->asInline()->setContentId('logo@yourapp.dev'));
        });

        $payload = $this->api->payload();
        $this->assertSame('logo@yourapp.dev', $payload['attachments'][0]['content_id']);
        $this->assertSame('<img src="cid:logo@yourapp.dev">', $payload['html']);
    }

    public function testTrackingHeadersBecomeFields(): void
    {
        Mail::raw('Hello', function (Message $m): void {
            $m->to('ann@inbox.dev')->subject('Hi');
            $m->getHeaders()->addTextHeader('X-SMTPfast-Track-Clicks', 'false');
            $m->getHeaders()->addTextHeader('X-SMTPfast-Track-Opens', 'TRUE');
        });

        $payload = $this->api->payload();
        $this->assertFalse($payload['track_clicks']);
        $this->assertTrue($payload['track_opens']);
        $this->assertArrayNotHasKey('headers', $payload);
        $this->assertArrayNotHasKey('html', $payload);
        $this->assertSame('Hello', $payload['text']);
    }

    public function testMailerCategory(): void
    {
        config(['mail.mailers.smtpfast.category' => 'transactional']);
        Mail::raw('Hello', fn (Message $m) => $m->to('ann@inbox.dev')->subject('Hi'));
        $this->assertSame('transactional', $this->api->payload()['category']);
    }

    public function testPackageCategory(): void
    {
        config(['smtpfast.category' => 'transactional']);
        Mail::raw('Hello', fn (Message $m) => $m->to('ann@inbox.dev')->subject('Hi'));
        $this->assertSame('transactional', $this->api->payload()['category']);
    }

    public function testApiErrorIsATransportException(): void
    {
        $this->api->replies[] = new Response(403, ['Content-Type' => 'application/json'], '{"error":"Domain yourapp.dev is not verified."}');
        try {
            Mail::raw('Hello', fn (Message $m) => $m->to('ann@inbox.dev')->subject('Hi'));
            $this->fail('no exception');
        } catch (TransportExceptionInterface $e) {
            $this->assertStringContainsString('403: Domain yourapp.dev is not verified.', $e->getMessage());
        }
    }

    public function testAnswerWithoutAnIdIsATransportException(): void
    {
        $this->api->replies[] = new Response(200, ['Content-Type' => 'application/json'], '{}');
        $this->expectException(TransportExceptionInterface::class);
        Mail::raw('Hello', fn (Message $m) => $m->to('ann@inbox.dev')->subject('Hi'));
    }

    public function testMailerWithItsOwnKeyGetsItsOwnClient(): void
    {
        config(['mail.mailers.team-b' => ['transport' => 'smtpfast', 'key' => 'sf_team_b']]);
        $transport = Mail::mailer('team-b')->getSymfonyTransport();
        $this->assertInstanceOf(SMTPfastTransport::class, $transport);
        $this->assertSame('smtpfast', (string) $transport);
        $client = (new \ReflectionProperty(SMTPfastTransport::class, 'client'))->getValue($transport);
        $this->assertNotSame($this->app->make(\SMTPfast\Client::class), $client);
    }
}
