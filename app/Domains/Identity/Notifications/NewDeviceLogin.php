<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class NewDeviceLogin extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $deviceName,
        public readonly string $ip,
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
            ->subject(__('mail.new_device.subject'))
            ->line(__('mail.new_device.line', ['device' => $this->deviceName, 'ip' => $this->ip, 'time' => now()->toDayDateTimeString()]))
            ->line(__('mail.new_device.not_you'))
            ->action(__('mail.new_device.action'), url('/settings/security'));
    }
}
