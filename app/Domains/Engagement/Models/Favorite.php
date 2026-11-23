<?php

declare(strict_types=1);

namespace App\Domains\Engagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * My Favorites (spec 1.7 / 5.17). `favoritable_type` is one of TYPES; no morph map needed because the
 * reader resolves each type explicitly.
 *
 * @property int $id
 * @property int $user_id
 * @property string $favoritable_type
 * @property int $favoritable_id
 * @property Carbon $created_at
 */
final class Favorite extends Model
{
    public const null UPDATED_AT = null;

    public const string OFFER = 'offer';

    public const string PRODUCT = 'product';

    /** Store and game×category favorites are in the schema; their buttons come with M16/M17. */
    public const array TYPES = [self::OFFER, self::PRODUCT, 'seller', 'game_category'];

    protected $fillable = ['user_id', 'favoritable_type', 'favoritable_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['favoritable_id' => 'integer'];
    }
}
