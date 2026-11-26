<?php

declare(strict_types=1);

namespace App\Shared\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One sent e-mail (recipient, subject, mailable) — never the body. Written by the MessageSent listener below.
 *
 * @property int $id
 * @property string $to
 * @property string $subject
 * @property string|null $mailable
 * @property Carbon $sent_at
 */
final class MailLog extends Model
{
    public $timestamps = false;

    protected $table = 'mail_log';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /** MessageSent listener: one row per recipient. A logging failure never breaks sending. */
    public static function record(MessageSent $event): void
    {
        try {
            $email = $event->message;
            $mailable = $event->data['__laravel_mailable'] ?? null;
            $subject = mb_substr((string) $email->getSubject(), 0, 255);
            foreach ([...$email->getTo(), ...$email->getCc(), ...$email->getBcc()] as $address) {
                self::query()->create([
                    'to' => mb_substr($address->getAddress(), 0, 255),
                    'subject' => $subject,
                    'mailable' => is_string($mailable) ? mb_substr(class_basename($mailable), 0, 120) : null,
                    'sent_at' => now(),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Mail log failed', ['error' => $e->getMessage()]);
        }
    }
}
