<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail verification code for account deletion (spec 6.8: "Email token"). */
final class AccountDeletionCode extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly int $minutes,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject((string) __('account.delete.mail.subject'))
            ->line((string) __('account.delete.mail.intro'))
            ->line('**'.$this->code.'**')
            ->line((string) __('account.delete.mail.expiry', ['minutes' => $this->minutes]))
            ->line((string) __('account.delete.mail.not_you'));
    }
}
