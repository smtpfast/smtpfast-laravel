<?php

declare(strict_types=1);

namespace SMTPfast\Laravel\Tests\Fixtures;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * The one email LiveTest sends: transactional, without click or open tracking, with a small attachment.
 */
final class LiveCheck extends Mailable
{
    public function __construct(private readonly string $label)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->label);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>A test of the smtpfast Laravel mail driver, sent to the Amazon SES mailbox simulator.</p>');
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'X-SMTPfast-Category' => 'transactional',
            'X-SMTPfast-Track-Clicks' => 'false',
            'X-SMTPfast-Track-Opens' => 'false',
        ]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => "check,ok\nattachment,1\n", 'live-check.csv')->withMime('text/csv')];
    }
}
