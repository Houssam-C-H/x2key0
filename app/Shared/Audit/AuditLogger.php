<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class AuditLogger
{
    /** Keys whose values never reach the audit table. */
    private const array REDACTED_KEYS = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
        'privacy_password_hash', 'mfa_secret', 'payload_ciphertext', 'payload_dek_wrapped',
        'details_encrypted', 'data_encrypted', 'tax_id_encrypted', 'buyer_inputs_encrypted',
        'ciphertext', 'dek_wrapped',
    ];

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly ?Request $request = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?Authenticatable $actor = null,
    ): AuditLog {
        $actor ??= $this->auth->guard()->user();

        return AuditLog::query()->create([
            'actor_type' => $actor instanceof Model ? $actor->getMorphClass() : null,
            'actor_id' => $actor?->getAuthIdentifier(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'before' => self::redact($before),
            'after' => self::redact($after),
            'ip' => $this->request?->ip(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public static function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach ($values as $key => $value) {
            if (in_array($key, self::REDACTED_KEYS, true)) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $values[$key] = self::redact($value);
            }
        }

        return $values;
    }
}
