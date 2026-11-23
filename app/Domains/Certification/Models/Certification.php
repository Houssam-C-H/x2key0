<?php

declare(strict_types=1);

namespace App\Domains\Certification\Models;

use App\Domains\Certification\Enums\CertificationStatus;
use App\Domains\Certification\Enums\CertificationType;
use App\Domains\Identity\Models\User;
use App\Shared\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property CertificationType $type
 * @property CertificationStatus $status
 * @property string|null $provider
 * @property string|null $provider_ref
 * @property array<string, mixed>|null $data_encrypted
 * @property int|null $reviewed_by
 * @property Carbon|null $verified_at
 * @property string|null $rejected_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
final class Certification extends Model
{
    use Auditable;

    protected $fillable = [
        'user_id', 'type', 'status', 'provider', 'provider_ref', 'data_encrypted',
        'reviewed_by', 'verified_at', 'rejected_reason',
    ];

    protected $hidden = ['data_encrypted'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CertificationType::class,
            'status' => CertificationStatus::class,
            'data_encrypted' => 'encrypted:array',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
