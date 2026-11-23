<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Records created / updated / deleted events of a model in `audit_logs`.
 * Only changed attributes are stored on update; secrets and $hidden fields are redacted.
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(static function (Model $model): void {
            self::auditLogger()->log(self::auditAction($model, 'created'), $model, null, self::auditValues($model, $model->getAttributes()));
        });

        static::updated(static function (Model $model): void {
            $changes = array_diff_key($model->getChanges(), array_flip(['updated_at']));

            if ($changes === []) {
                return;
            }

            $before = array_intersect_key($model->getOriginal(), $changes);

            self::auditLogger()->log(self::auditAction($model, 'updated'), $model, self::auditValues($model, $before), self::auditValues($model, $changes));
        });

        static::deleted(static function (Model $model): void {
            self::auditLogger()->log(self::auditAction($model, 'deleted'), $model, self::auditValues($model, $model->getOriginal()), null);
        });
    }

    private static function auditLogger(): AuditLogger
    {
        return app(AuditLogger::class);
    }

    private static function auditAction(Model $model, string $event): string
    {
        return $model->getTable().'.'.$event;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function auditValues(Model $model, array $values): array
    {
        foreach ($model->getHidden() as $hidden) {
            if (array_key_exists($hidden, $values)) {
                $values[$hidden] = '[redacted]';
            }
        }

        return AuditLogger::redact($values) ?? [];
    }
}
