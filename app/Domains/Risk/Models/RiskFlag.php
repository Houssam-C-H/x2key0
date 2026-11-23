<?php

declare(strict_types=1);

namespace App\Domains\Risk\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Something staff should look at (spec 5.17 / 17.1): risk review reasons, blocked checkouts, payment
 * amount mismatches, late captures. Subject = order | user | payment | storage_item.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $rule
 * @property int $score
 * @property array<string, mixed>|null $details
 * @property string $status
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 */
final class RiskFlag extends Model
{
    public const null UPDATED_AT = null;

    protected $fillable = ['subject_type', 'subject_id', 'rule', 'score', 'details', 'status'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'details' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function raise(string $subjectType, int $subjectId, string $rule, int $score = 0, array $details = []): self
    {
        return self::query()->create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'rule' => $rule,
            'score' => $score,
            'details' => $details === [] ? null : $details,
            'status' => 'open',
        ]);
    }
}
