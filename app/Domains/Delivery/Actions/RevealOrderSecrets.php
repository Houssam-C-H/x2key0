<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Actions;

use App\Domains\Delivery\Models\Delivery;
use App\Domains\Delivery\Models\DeliverySecret;
use App\Domains\Delivery\Models\SecretReveal;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use App\Domains\Storage\Contracts\SecretVault;
use App\Domains\Storage\Models\StorageItem;
use Illuminate\Support\Facades\DB;

/**
 * Buyer reveal (spec 8.3): decrypts what was delivered for the order — storage items assigned by auto
 * delivery and secrets typed by the seller — and writes one secret_reveals row per secret (dispute and
 * chargeback evidence). The route sits behind a recent password confirmation and a rate limit.
 */
final class RevealOrderSecrets
{
    public function __construct(private readonly SecretVault $vault) {}

    /**
     * @return list<array{deliveryId: int, kind: string, value: string|null, account: array<string, string|null>|null}>
     */
    public function handle(Order $order, User $buyer, ?string $ip, ?string $userAgent): array
    {
        abort_unless($order->buyer_id === $buyer->id, 404);
        $order->loadMissing('item');

        $out = [];
        $logs = [];
        $now = now();

        foreach (Delivery::query()->where('order_item_id', $order->item->id)->with(['storageItems.group', 'secrets'])->orderBy('id')->get() as $delivery) {
            foreach ($delivery->storageItems as $storageItem) {
                /** @var StorageItem $storageItem */
                $out[] = self::shape($delivery->id, $storageItem->group->item_type->value === 'account' ? 'account' : 'key', $this->vault->open($storageItem->sealed()));
                $logs[] = ['storage_item_id' => $storageItem->id, 'delivery_secret_id' => null];
            }
            foreach ($delivery->secrets as $secret) {
                /** @var DeliverySecret $secret */
                $out[] = self::shape($delivery->id, $secret->kind, $this->vault->open($secret->sealed()));
                $logs[] = ['storage_item_id' => null, 'delivery_secret_id' => $secret->id];
            }
        }

        DB::table('secret_reveals')->insert(array_map(static fn (array $l): array => [
            ...$l,
            'order_item_id' => $order->item->id,
            'user_id' => $buyer->id,
            'ip' => $ip,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            'created_at' => $now,
        ], $logs));

        return $out;
    }

    /**
     * @return array{deliveryId: int, kind: string, value: string|null, account: array<string, string|null>|null}
     */
    private static function shape(int $deliveryId, string $kind, string $plaintext): array
    {
        if ($kind === 'account') {
            /** @var array<string, string|null> $account */
            $account = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);

            return ['deliveryId' => $deliveryId, 'kind' => 'account', 'value' => null, 'account' => $account];
        }

        return ['deliveryId' => $deliveryId, 'kind' => $kind, 'value' => $plaintext, 'account' => null];
    }

    /** Count of reveals for an order (shown to staff as evidence). */
    public static function count(Order $order): int
    {
        return SecretReveal::query()->where('order_item_id', $order->item->id ?? 0)->count();
    }
}
