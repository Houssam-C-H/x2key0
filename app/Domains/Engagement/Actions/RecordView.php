<?php

declare(strict_types=1);

namespace App\Domains\Engagement\Actions;

use App\Domains\Engagement\Models\BrowsingHistory;
use App\Shared\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Records a product or offer view in the browsing history (spec 5.17) for a user or a guest token.
 * Re-viewing moves the item to the front; the list is trimmed to `browsing_history_limit`.
 */
final class RecordView
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(?int $userId, ?string $guestToken, ?int $productId, ?int $offerId): void
    {
        if (($userId === null && $guestToken === null) || ($productId === null) === ($offerId === null)) {
            throw new InvalidArgumentException('A view needs one owner and exactly one product or offer.');
        }

        $owner = static fn (Builder $q): Builder => $userId !== null ? $q->where('user_id', $userId) : $q->whereNull('user_id')->where('guest_token', $guestToken);

        $owner(BrowsingHistory::query())
            ->where($productId !== null ? 'product_id' : 'offer_id', $productId ?? $offerId)
            ->delete();

        BrowsingHistory::query()->create([
            'user_id' => $userId,
            'guest_token' => $userId === null ? $guestToken : null,
            'product_id' => $productId,
            'offer_id' => $offerId,
            'viewed_at' => now(),
        ]);

        $limit = $this->settings->int('browsing_history_limit');
        $keep = $owner(BrowsingHistory::query())->orderByDesc('viewed_at')->orderByDesc('id')->limit($limit)->pluck('id');

        $owner(BrowsingHistory::query())->whereNotIn('id', $keep)->delete();
    }
}
