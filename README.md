# SMTPfast for Laravel

Send Laravel mail through the [SMTPfast](https://smtpfa.st) API. The package adds an `smtpfast` mail driver, so your existing Mailables and Notifications send through the API, and makes the [SMTPfast PHP SDK](https://github.com/smtpfast/smtpfast-php) client available in the container. It supports Laravel 12 and 13 on PHP 8.2+ (Laravel 13 needs PHP 8.3+).

```bash
composer require smtpfast/smtpfast-laravel
```

## Send mail

Set two variables in `.env`:

```dotenv
MAIL_MAILER=smtpfast
SMTPFAST_API_KEY=sf_...
```

That is all. The package registers the service provider and the `smtpfast` mailer itself; you do not need to edit `config/mail.php`. Mail goes out from the address in `MAIL_FROM_ADDRESS`, which must be on a domain you verified in SMTPfast.

```php
Mail::to($user)->send(new OrderShipped($order));
$user->notify(new InvoicePaid($invoice));
```

To keep another default mailer and use SMTPfast for some mail only, call `Mail::mailer('smtpfast')`.

## Transactional or marketing

Email is marketing unless you set it to transactional. Marketing email gets a one-click unsubscribe header and is not sent to people who unsubscribed. Set transactional on receipts, password resets and account alerts so a marketing opt-out does not block them.

For every message of the mailer, set `SMTPFAST_CATEGORY=transactional`. To choose per message, add a header to the Mailable; it becomes the `category` field of the request:

```php
public function headers(): Headers
{
    return new Headers(text: ['X-SMTPfast-Category' => 'transactional']);
}
```

`X-SMTPfast-Track-Clicks` and `X-SMTPfast-Track-Opens` set to `true` or `false` work the same way, for `track_clicks` and `track_opens`.

## What is sent

The driver sends one `POST /v1/emails` request per message:

| Laravel message | SMTPfast request |
| --- | --- |
| From | `from` |
| To, Cc, Bcc | `to`, `cc`, `bcc` |
| Reply-To | `reply_to` |
| Subject | `subject` |
| HTML and text views | `html` and `text` |
| Attachments | `attachments`, base64 |
| `$message->embed()` and other inline parts | `attachments` with `content_id`, matching the `cid:` in the HTML |
| `metadata()` | `tags`, as name and value |
| Other headers, including `tag()` as `X-Tag` | `headers`, with their raw values |

The SMTPfast email id becomes the message id: `$sent->getMessageId()` on the result of `Mail::send()`, and on the `MessageSent` event. A send is retried with the same `Idempotency-Key`, so a retry never sends twice. An error from the API is a Symfony `TransportException` that holds the API's message.

## Configuration

The package works without a config file. To change the defaults, publish it:

```bash
php artisan vendor:publish --tag=smtpfast-config
```

| Variable | Default | |
| --- | --- | --- |
| `SMTPFAST_API_KEY` | | The API key. `services.smtpfast.key` also works, like Laravel's built-in mail drivers. |
| `SMTPFAST_BASE_URL` | `https://smtpfa.st/api` | |
| `SMTPFAST_TIMEOUT` | `60` | Seconds per attempt. |
| `SMTPFAST_MAX_RETRIES` | `2` | After 429 (with Retry-After), 408, 5xx and dropped connections. |
| `SMTPFAST_CATEGORY` | | `transactional` or `marketing` for mail without an `X-SMTPfast-Category` header. |
| `SMTPFAST_WEBHOOK_SECRET` | | The webhook's signing secret. |
| `SMTPFAST_WEBHOOK_PATH` | | A path such as `smtpfast/webhook` turns on the webhook route. |

A mailer in `config/mail.php` can have its own `key` and `category`, for example to send from a second SMTPfast team:

```php
'mailers' => [
    'smtpfast-receipts' => ['transport' => 'smtpfast', 'key' => env('SMTPFAST_RECEIPTS_KEY'), 'category' => 'transactional'],
],
```

## The API client

The container has a `SMTPfast\Client` singleton, configured from the same settings. Inject it, or use the facade, where each API group is a method:

```php
use SMTPfast\Client;
use SMTPfast\Laravel\Facades\SMTPfast;

public function __invoke(Client $smtpfast)
{
    $smtpfast->domains->verify($id);
}

SMTPfast::contacts()->create(['email' => 'ann@example.org']);
```

See the [PHP SDK](https://github.com/smtpfast/smtpfast-php) for every method, errors, retries and pagination.

## Webhooks

Set the secret and a path:

```dotenv
SMTPFAST_WEBHOOK_SECRET=whsec_...
SMTPFAST_WEBHOOK_PATH=smtpfast/webhook
```

The package then answers `POST /smtpfast/webhook`. It checks the `X-SMTPfast-Signature` header (the hex HMAC-SHA256 of the raw body, keyed with the secret), answers 401 when it does not match, and dispatches `SMTPfast\Laravel\Events\WebhookReceived` with the decoded event:

```php
use SMTPfast\Laravel\Events\WebhookReceived;

Event::listen(function (WebhookReceived $event) {
    if ($event->payload['type'] === 'email.bounced') {
        // ...
    }
});
```

The route has no session or CSRF middleware. To use your own route instead, leave the path empty and add the `smtpfast.webhook` middleware:

```php
Route::post('/hooks/smtpfast', SMTPfastWebhookController::class)->middleware('smtpfast.webhook');
```

## Development

```bash
composer install
vendor/bin/phpunit
```

Until `smtpfast/smtpfast-php` is on Packagist, `composer.json` gets it from its GitHub repository (the `repositories` entry, with `minimum-stability: dev` and `prefer-stable`). Composer then uses the `0.1.x-dev` branch alias of its main branch, and the first tagged release once there is one. To work on both packages together, point Composer at a local checkout:

```bash
composer config repositories.smtpfast-php path ../smtpfast-php
composer update smtpfast/smtpfast-php
```

## Releases

Packagist publishes a version for every git tag, so there is no release workflow. To release, tag the commit, for example `git tag v0.1.0 && git push origin v0.1.0`.

## License

MIT
