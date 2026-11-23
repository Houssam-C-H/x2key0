<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Product;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Models\Offer;
use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Providers\FakePaymentProvider;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Storage\Actions\ImportStorageItems;
use App\Domains\Storage\Enums\StorageItemType;
use App\Domains\Storage\Models\StorageGroup;
use App\Domains\Storage\Models\StorageItem;
use App\Domains\Storage\Support\StoragePayload;
use Database\Factories\GameCategoryFactory;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A buyer, a seller and two live offers:
 *   manual  — "Coins pack", $10.00, 5 in stock, game×category with a required Player ID (6–12 digits)
 *   auto    — "Steam key", $4.00, delivered from a storage group holding 3 keys
 * plus the fake payment methods (fake_card 0 %, fake_wallet 1.5 % + $0.10).
 */
final class CheckoutFixture
{
    /**
     * @return array{buyer: User, seller: SellerProfile, manual: Offer, auto: Offer, group: StorageGroup}
     */
    public static function build(): array
    {
        (new PaymentMethodSeeder)->run();

        GameCategoryFactory::type(CategoryTypeCode::TopUp);
        $topUp = GameCategory::factory()->ofType(CategoryTypeCode::TopUp)->create([
            'buyer_input_schema' => [
                ['key' => 'player_id', 'type' => 'text', 'label' => ['en' => 'Player ID'], 'required' => true, 'pattern' => '^[0-9]{6,12}$'],
                ['key' => 'server', 'type' => 'select', 'label' => ['en' => 'Server'], 'required' => false, 'options' => ['eu', 'na']],
            ],
        ]);
        $keys = GameCategory::factory()->create();

        $seller = SellerProfile::factory()->create(['store_name' => 'Pixel Vault', 'slug' => 'pixel-vault']);
        $buyer = User::factory()->create(['created_at' => now()->subMonth()]);

        $manual = Offer::factory()->create([
            'seller_profile_id' => $seller->id,
            'game_category_id' => $topUp->id,
            'title' => 'Coins pack',
            'delivery_method' => 'manual_top_up',
            'unit_price_amount' => 1000,
            'manual_stock_qty' => 5,
            'delivery_time_minutes' => 30,
        ]);

        $group = StorageGroup::query()->create(['seller_profile_id' => $seller->id, 'name' => 'Keys', 'item_type' => StorageItemType::Key, 'low_stock_threshold' => 0]);
        app(ImportStorageItems::class)->handle($group, $seller->user, 'paste', StoragePayload::parse(StorageItemType::Key, "KEY-AAA-1\nKEY-BBB-2\nKEY-CCC-3"));

        $product = Product::factory()->for($keys)->create(['name' => ['en' => 'Space Saga'], 'slug' => 'space-saga']);
        $auto = Offer::factory()->forProduct($product)->fromStorage($group)->create(['title' => 'Steam key', 'unit_price_amount' => 400]);

        return ['buyer' => $buyer, 'seller' => $seller, 'manual' => $manual->refresh(), 'auto' => $auto->refresh(), 'group' => $group->refresh()];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function form(Offer $offer, array $overrides = []): array
    {
        return [
            'offer' => $offer->public_id,
            'quantity' => $offer->min_qty,
            'buyer_inputs' => $offer->title === 'Coins pack' ? ['player_id' => '12345678', 'server' => 'eu'] : [],
            'method' => 'fake_card',
            'use_credits' => false,
            'idempotency_key' => strtolower((string) Str::ulid()),
            ...$overrides,
        ];
    }

    public static function lastOrder(User $buyer): Order
    {
        return Order::query()->where('buyer_id', $buyer->id)->latest('id')->firstOrFail();
    }

    public static function pspPayment(Order $order): Payment
    {
        return Payment::query()->where('order_id', $order->id)->where('provider', '!=', Payment::CREDITS)->sole();
    }

    /** Sends a signed fake-PSP webhook for the order's PSP payment. */
    public static function webhook(Order $order, PaymentStatus $status, ?int $amount = null, ?string $currency = null, ?string $eventId = null, ?string $signature = null): TestResponse
    {
        $payment = self::pspPayment($order);
        $psp = app(FakePaymentProvider::class);
        $body = $psp->webhookBody((string) $payment->provider_payment_id, $status, $amount ?? $payment->amount, $currency ?? $payment->currency, $eventId);

        return test()->call('POST', '/webhooks/fake', [], [], [], [
            'HTTP_X_FAKE_SIGNATURE' => $signature ?? $psp->sign($body),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    /** Available (unreserved, unfrozen) items left in the group. */
    public static function availableKeys(StorageGroup $group): int
    {
        return StorageItem::query()->where('storage_group_id', $group->id)->where('status', 'available')->count();
    }
}
