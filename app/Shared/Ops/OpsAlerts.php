<?php

declare(strict_types=1);

namespace App\Shared\Ops;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers health-check alerts (docs/RUNBOOK.md §2) to `x2key.ops.alert_email` and/or the Slack incoming webhook
 * `x2key.ops.alert_slack_webhook`: once when a check starts failing, again every `alert_repeat_minutes` while it
 * stays down, and once when it recovers. State per check lives in the cache, so `ops:check` every five minutes
 * never floods anyone. Messages carry check names and short details only — never personal data or secrets.
 */
final class OpsAlerts
{
    private const string STATE = 'ops:alert:';

    /**
     * @param  array<string, array{ok: bool, detail: string}>  $checks
     * @return list<string> messages sent
     */
    public function notify(array $checks): array
    {
        $sent = [];
        $repeat = max(5, config()->integer('x2key.ops.alert_repeat_minutes')) * 60;

        foreach ($checks as $name => $check) {
            $state = Cache::get(self::STATE.$name);
            $wasFailing = is_array($state) && ($state['failing'] ?? false) === true;
            $lastAlert = is_array($state) && is_int($state['alerted'] ?? null) ? $state['alerted'] : 0;

            if (! $check['ok'] && (! $wasFailing || now()->getTimestamp() - $lastAlert >= $repeat)) {
                $sent[] = $this->send("FAILING {$name}: {$check['detail']}");
                Cache::forever(self::STATE.$name, ['failing' => true, 'alerted' => now()->getTimestamp(), 'since' => $wasFailing ? ($state['since'] ?? now()->getTimestamp()) : now()->getTimestamp()]);
            } elseif ($check['ok'] && $wasFailing) {
                $sent[] = $this->send("RECOVERED {$name}: {$check['detail']}");
                Cache::forget(self::STATE.$name);
            }
        }

        return $sent;
    }

    private function send(string $message): string
    {
        $text = '['.config()->string('app.name').' '.app()->environment().'] '.$message;

        $email = config('x2key.ops.alert_email');
        if (is_string($email) && $email !== '') {
            try {
                Mail::raw($text."\n\nRunbook: docs/RUNBOOK.md §2. Health: ".rtrim(config()->string('app.url'), '/').'/health', static function (Message $m) use ($email, $text): void {
                    $m->to($email)->subject(mb_substr($text, 0, 150));
                });
            } catch (Throwable $e) {
                Log::error('ops alert e-mail failed', ['error' => $e->getMessage()]);
            }
        }

        $webhook = config('x2key.ops.alert_slack_webhook');
        if (is_string($webhook) && str_starts_with($webhook, 'https://')) {
            try {
                Http::timeout(5)->post($webhook, ['text' => $text]);
            } catch (Throwable $e) {
                Log::error('ops alert webhook failed', ['error' => $e->getMessage()]);
            }
        }

        return $text;
    }
}
