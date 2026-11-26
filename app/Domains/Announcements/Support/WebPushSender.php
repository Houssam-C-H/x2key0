<?php

declare(strict_types=1);

namespace App\Domains\Announcements\Support;

use App\Domains\Announcements\Models\PushSubscription;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sends a notification to every subscribed device of a user (ADR-037). Expired subscriptions (404/410 from the
 * browser's push service) are deleted. No-op when VAPID keys are not configured.
 */
final class WebPushSender
{
    public static function enabled(): bool
    {
        return filled(config('x2key.push.public_key')) && filled(config('x2key.push.private_key'));
    }

    /**
     * @param  array{title: string, body: string, url: string}  $message
     * @return int deliveries accepted by the push services
     */
    public function send(int $userId, array $message): int
    {
        if (! self::enabled()) {
            return 0;
        }

        $subscriptions = PushSubscription::query()->where('user_id', $userId)->get();
        if ($subscriptions->isEmpty()) {
            return 0;
        }

        $push = new WebPush(['VAPID' => [
            'subject' => config()->string('x2key.push.subject'),
            'publicKey' => config()->string('x2key.push.public_key'),
            'privateKey' => config()->string('x2key.push.private_key'),
        ]], ['TTL' => 86400, 'urgency' => 'normal']);

        $payload = (string) json_encode($message);
        foreach ($subscriptions as $s) {
            $push->queueNotification(Subscription::create([
                'endpoint' => $s->endpoint,
                'publicKey' => $s->public_key,
                'authToken' => $s->auth_token,
                'contentEncoding' => $s->content_encoding,
            ]), $payload);
        }

        $ok = 0;
        foreach ($push->flush() as $report) {
            if (! $report instanceof MessageSentReport) {
                continue;
            }
            $hash = hash('sha256', $report->getEndpoint());
            if ($report->isSuccess()) {
                $ok++;
                PushSubscription::query()->where('endpoint_hash', $hash)->update(['last_used_at' => now()]);
            } elseif ($report->isSubscriptionExpired()) {
                PushSubscription::query()->where('endpoint_hash', $hash)->delete();
            }
        }

        return $ok;
    }
}
