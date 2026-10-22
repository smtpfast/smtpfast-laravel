<?php

declare(strict_types=1);

namespace SMTPfast\Laravel\Tests\Fixtures;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

final class OrderShipped extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('orders@yourapp.dev', 'Acme Orders'),
            replyTo: [new Address('help@yourapp.dev', 'Acme Help')],
            subject: 'Your order shipped',
            tags: ['shipping'],
            metadata: ['order_id' => '1234'],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'shipped', text: 'shipped-text', with: ['order' => '1234']);
    }

    public function headers(): Headers
    {
        return new Headers(text: ['X-Entity-Ref-ID' => 'abc-123', 'X-SMTPfast-Category' => 'Transactional']);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => "sku,qty\nlamp,1\n", 'order.csv')->withMime('text/csv')];
    }
}
