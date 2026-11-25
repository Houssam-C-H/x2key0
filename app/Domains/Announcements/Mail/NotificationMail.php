<?php

declare(strict_types=1);

namespace App\Domains\Announcements\Mail;

use App\Domains\Identity\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail copy of an inbox notification (Notifier::EMAILED): same `notify.{type}.title|body` strings, in the
 * user's language, with a button to the page. Never carries secrets (Notifier data never does).
 */
final class NotificationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, string|int>  $data
     */
    public function __construct(
        public readonly User $user,
        public readonly string $type,
        public readonly array $data,
    ) {
        $this->locale($user->locale ?: config()->string('app.locale'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->translate("notify.{$this->type}.title", $this->data));
    }

    public function content(): Content
    {
        $href = isset($this->data['href']) ? (string) $this->data['href'] : null;

        return new Content(markdown: 'mail.notification', with: [
            'title' => $this->translate("notify.{$this->type}.title", $this->data),
            'body' => $this->translate("notify.{$this->type}.body", $this->data),
            'action' => $this->translate('mail.notification.action'),
            'url' => $href === null || $href === '' ? null : (str_starts_with($href, 'http') ? $href : rtrim(config()->string('app.url'), '/').'/'.ltrim($href, '/')),
        ]);
    }

    /**
     * @param  array<string, string|int>  $data
     */
    private function translate(string $key, array $data = []): string
    {
        $replace = array_map(static fn (string|int $v): string => (string) $v, array_filter($data, static fn (mixed $v, string $k): bool => $k !== 'href', ARRAY_FILTER_USE_BOTH));
        $text = __($key, $replace, $this->locale);

        return is_string($text) ? $text : $key;
    }
}
