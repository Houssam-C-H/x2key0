<?php

declare(strict_types=1);

namespace App\Domains\Engagement\Actions;

use App\Domains\Catalog\Models\Product;
use App\Domains\Engagement\Models\Favorite;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Models\Offer;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Adds or removes a favorite (spec Part 20 `POST /api/favorites (toggle)`). Offers and products can be
 * favorited when they are publicly visible; removing always works. Returns the new state.
 */
final class ToggleFavorite
{
    public const int MAX_PER_USER = 500;

    public function handle(User $user, string $type, string $publicId): bool
    {
        $id = match ($type) {
            Favorite::OFFER => Offer::query()->where('public_id', $publicId)->value('id'),
            Favorite::PRODUCT => Product::query()->where('public_id', $publicId)->value('id'),
            default => null,
        };
        abort_unless(is_int($id), 404);

        $existing = Favorite::query()->where(['user_id' => $user->id, 'favoritable_type' => $type, 'favoritable_id' => $id])->first();

        if ($existing !== null) {
            $existing->delete();

            return false;
        }

        $visible = match ($type) {
            Favorite::OFFER => Offer::query()->live()->whereKey($id)->exists(),
            default => Product::query()->active()->whereKey($id)->whereHas('gameCategory', fn ($q) => $q->visible())->exists(),
        };
        abort_unless($visible, 404);

        abort_if(Favorite::query()->where('user_id', $user->id)->count() >= self::MAX_PER_USER, 422, __('favorites.error.limit'));

        try {
            Favorite::query()->create(['user_id' => $user->id, 'favoritable_type' => $type, 'favoritable_id' => $id]);
        } catch (UniqueConstraintViolationException) {
            // Double click: already favorited.
        }

        return true;
    }
}
