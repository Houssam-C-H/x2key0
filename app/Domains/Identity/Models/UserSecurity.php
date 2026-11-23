<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Second password protecting sensitive areas (Storage, deletion) — spec 1.9 / 23.
 *
 * @property int $user_id
 * @property string|null $privacy_password_hash
 * @property Carbon|null $privacy_password_set_at
 */
final class UserSecurity extends Model
{
    protected $table = 'user_security';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'privacy_password_hash', 'privacy_password_set_at'];

    protected $hidden = ['privacy_password_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['privacy_password_set_at' => 'datetime'];
    }
}
