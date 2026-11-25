<?php

declare(strict_types=1);

namespace App\Domains\Engagement\Actions;

use App\Domains\Announcements\Support\Notifier;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Product;
use App\Domains\Engagement\Models\PriceAlert;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Models\Offer;
use Brick\Money\Money;
use DomainException;

/**
 * Price alerts: set / remove per user and target (a catalog product or a game×category), and the scheduled check
 * (`alerts:prices`, every ten minutes) that fires once when the lowest live price reaches the target and re-arms
 * when it goes back above. Prices are compared in USD cents like every listing.
 */
final class PriceAlerts
{
    public const int MAX_PER_USER = 50;

    public function set(User $user, string $type, string $id, int $targetCents): PriceAlert
    {
        if ($targetCents <= 0) {
            throw new DomainException((string) __('price_alert.error.amount'));
        }

        [$column, $targetId] = self::resolve($type, $id);

        $alert = PriceAlert::query()->where('user_id', $user->id)->where($column, $targetId)->first();
        if ($alert === null && PriceAlert::query()->where('user_id', $user->id)->count() >= self::MAX_PER_USER) {
            throw new DomainException((string) __('price_alert.error.limit', ['max' => self::MAX_PER_USER]));
        }

        $alert ??= new PriceAlert(['user_id' => $user->id, $column => $targetId]);
        $alert->forceFill([
            'product_id' => $column === 'product_id' ? $targetId : null,
            'game_category_id' => $column === 'game_category_id' ? $targetId : null,
            'target_amount' => $targetCents,
            'currency' => 'USD',
            'notified_at' => null,
        ])->save();

        return $alert;
    }

    public function remove(User $user, int $alertId): void
    {
        PriceAlert::query()->where('user_id', $user->id)->whereKey($alertId)->delete();
    }

    /** @return int alerts fired */
    public function check(): int
    {
        $fired = 0;

        PriceAlert::query()->with(['product.gameCategory', 'gameCategory.game', 'gameCategory.type'])->chunkById(500, function ($alerts) use (&$fired): void {
            foreach ($alerts as $alert) {
                $lowest = self::lowest($alert);
                $reached = $lowest !== null && $lowest <= $alert->target_amount;

                if ($reached && $alert->notified_at === null) {
                    $alert->forceFill(['notified_at' => now()])->save();
                    Notifier::notify($alert->user_id, 'price_drop', 'announcement', [
                        'title' => self::title($alert),
                        'price' => self::usd($lowest),
                        'target' => self::usd($alert->target_amount),
                        'href' => self::href($alert),
                    ]);
                    $fired++;
                } elseif (! $reached && $alert->notified_at !== null) {
                    $alert->forceFill(['notified_at' => null])->save(); // back above the target: re-armed
                }
            }
        });

        return $fired;
    }

    /** Lowest live price in USD cents for the alert's target, or null when nothing is on sale. */
    public static function lowest(PriceAlert $alert): ?int
    {
        if ($alert->product !== null) {
            return $alert->product->lowest_price_amount_cached;
        }
        if ($alert->game_category_id === null) {
            return null;
        }
        $min = Offer::query()->live()->where('offers.game_category_id', $alert->game_category_id)->min('offers.unit_price_amount');

        return is_numeric($min) ? (int) $min : null;
    }

    public static function title(PriceAlert $alert, string $locale = 'en'): string
    {
        return $alert->product?->translate('name', $locale) ?? $alert->gameCategory?->title($locale) ?? '';
    }

    public static function href(PriceAlert $alert, string $locale = 'en'): string
    {
        return $alert->product?->path($locale) ?? $alert->gameCategory?->path($locale) ?? '/'.$locale;
    }

    /**
     * @return array{string, int}
     */
    private static function resolve(string $type, string $id): array
    {
        if ($type === 'product') {
            $productId = Product::query()->active()->where('public_id', $id)->value('id');
            if (is_int($productId)) {
                return ['product_id', $productId];
            }
        } elseif ($type === 'category' && ctype_digit($id)) {
            $gcId = GameCategory::query()->visible()->whereKey((int) $id)->value('game_categories.id');
            if (is_int($gcId)) {
                return ['game_category_id', $gcId];
            }
        }

        throw new DomainException((string) __('price_alert.error.target'));
    }

    private static function usd(int $cents): string
    {
        return 'USD '.Money::ofMinor($cents, 'USD')->getAmount();
    }
}
