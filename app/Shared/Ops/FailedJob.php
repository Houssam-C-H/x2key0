<?php

declare(strict_types=1);

namespace App\Shared\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Read model over Laravel's `failed_jobs` table for the admin list. Retry and forget go through QueueHealth.
 *
 * @property int $id
 * @property string $uuid
 * @property string $connection
 * @property string $queue
 * @property string $payload
 * @property string $exception
 * @property Carbon $failed_at
 */
final class FailedJob extends Model
{
    public $timestamps = false;

    protected $table = 'failed_jobs';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['failed_at' => 'datetime'];
    }

    public function jobName(): string
    {
        $payload = json_decode($this->payload, true);
        $name = is_array($payload) ? ($payload['displayName'] ?? $payload['job'] ?? null) : null;

        return is_string($name) ? class_basename($name) : 'unknown';
    }

    public function shortError(): string
    {
        return mb_strimwidth(strtok($this->exception, "\n") ?: '', 0, 200, '…');
    }
}
