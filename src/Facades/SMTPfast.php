<?php

declare(strict_types=1);

namespace SMTPfast\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use SMTPfast\Client;

/**
 * The SMTPfast API client. Each API group is a method: SMTPfast::emails()->send([...]).
 *
 * @method static \SMTPfast\Resources\AccountResource account()
 * @method static \SMTPfast\Resources\AnalyticsResource analytics()
 * @method static \SMTPfast\Resources\ApiKeysResource apiKeys()
 * @method static \SMTPfast\Resources\BroadcastsResource broadcasts()
 * @method static \SMTPfast\Resources\ContactPropertiesResource contactProperties()
 * @method static \SMTPfast\Resources\ContactsResource contacts()
 * @method static \SMTPfast\Resources\DomainsResource domains()
 * @method static \SMTPfast\Resources\EmailsResource emails()
 * @method static \SMTPfast\Resources\FormsResource forms()
 * @method static \SMTPfast\Resources\InboxesResource inboxes()
 * @method static \SMTPfast\Resources\LinkDomainsResource linkDomains()
 * @method static \SMTPfast\Resources\LogsResource logs()
 * @method static \SMTPfast\Resources\SegmentsResource segments()
 * @method static \SMTPfast\Resources\SuppressionsResource suppressions()
 * @method static \SMTPfast\Resources\TeamResource team()
 * @method static \SMTPfast\Resources\TemplatesResource templates()
 * @method static \SMTPfast\Resources\WebhooksResource webhooks()
 *
 * @see Client
 */
class SMTPfast extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }

    /**
     * The client's groups are properties; the facade returns them for a call without arguments.
     *
     * @param string $method
     * @param array<int, mixed> $args
     */
    public static function __callStatic($method, $args): mixed
    {
        $client = static::getFacadeRoot();
        if ($args === [] && is_object($client) && property_exists($client, $method)) {
            return $client->{$method};
        }

        return parent::__callStatic($method, $args);
    }
}
