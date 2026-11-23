<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 * @property bool $bookmark_prompt_dismissed
 * @property bool $notification_mute
 * @property bool $seller_promo_emails
 * @property array<string, bool>|null $email_prefs
 */
final class UserPreference extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'bookmark_prompt_dismissed', 'notification_mute', 'seller_promo_emails', 'email_prefs'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bookmark_prompt_dismissed' => 'boolean',
            'notification_mute' => 'boolean',
            'seller_promo_emails' => 'boolean',
            'email_prefs' => 'array',
        ];
    }
}
