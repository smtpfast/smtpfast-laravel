<?php

declare(strict_types=1);

namespace SMTPfast\Laravel\Tests\Fixtures;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PasswordReset extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Reset your password')
            ->line('Someone asked to reset your password.')
            ->action('Reset password', 'https://yourapp.dev/reset/abc');
    }
}
