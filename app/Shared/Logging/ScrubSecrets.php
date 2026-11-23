<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use App\Shared\Audit\AuditLogger;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Log scrubber (spec Part 23): redacts secret-bearing keys from log context and extra data, at any depth.
 * Attached to the file/stderr channels with `tap` in config/logging.php.
 */
final class ScrubSecrets
{
    /** Request/field names that can carry storage secrets or credentials, on top of AuditLogger's list. */
    private const array KEYS = ['items', 'payload', 'plaintext', 'secret', 'account', 'privacy_password', 'current_password', 'password_confirmation'];

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof \Monolog\Logger) {
            $monolog->pushProcessor(static fn (LogRecord $record): LogRecord => $record->with(
                context: self::scrub($record->context),
                extra: self::scrub($record->extra),
            ));
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function scrub(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::KEYS, true)) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = self::scrub($value);
            }
        }

        /** @var array<string, mixed> $values */
        return AuditLogger::redact($values) ?? [];
    }
}
