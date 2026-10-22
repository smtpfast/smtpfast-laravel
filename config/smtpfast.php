<?php

declare(strict_types=1);

return [

    /*
     * The API key. Like Laravel's built-in mail drivers, the package also
     * reads services.smtpfast.key when this is empty.
     */
    'api_key' => env('SMTPFAST_API_KEY'),

    'base_url' => env('SMTPFAST_BASE_URL', 'https://smtpfa.st/api'),

    // Seconds per attempt, and retries after 429 (with Retry-After), 408, 5xx and dropped connections.
    'timeout' => (float) env('SMTPFAST_TIMEOUT', 60),
    'max_retries' => (int) env('SMTPFAST_MAX_RETRIES', 2),

    /*
     * Mail sent with the smtpfast mailer is marketing unless it says
     * otherwise. Set transactional here for every message of the mailer, or
     * add an X-SMTPfast-Category header to one message. A mailer in
     * config/mail.php can set its own 'category'.
     */
    'category' => env('SMTPFAST_CATEGORY'),

    'webhook' => [
        // The webhook's signing secret, from the SMTPfast dashboard.
        'secret' => env('SMTPFAST_WEBHOOK_SECRET'),

        // Set a path, such as smtpfast/webhook, to receive events there as
        // SMTPfast\Laravel\Events\WebhookReceived. Empty: no route.
        'path' => env('SMTPFAST_WEBHOOK_PATH'),
    ],

];
