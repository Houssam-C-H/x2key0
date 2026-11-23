<?php

declare(strict_types=1);

use App\Domains\Orders\Models\Order;
use App\Domains\Storage\Enums\StorageItemStatus;
use App\Domains\Storage\Models\StorageItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\CheckoutFixture;

/*
| Real lock contention on PostgreSQL (spec 24 "Concurrency"): a second connection holds row locks while the
| app checks out. Rows are committed (DatabaseTruncation), so both connections see them.
*/

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Lock contention needs PostgreSQL.');
    }

    config(['database.connections.race' => config('database.connections.pgsql')]);
    $this->fx = CheckoutFixture::build();
    $this->race = DB::connection('race');
});

afterEach(function (): void {
    if (isset($this->race) && $this->race->transactionLevel() > 0) {
        $this->race->rollBack();
    }
    DB::purge('race');
});

it('never hands the last storage item to two checkouts: SKIP LOCKED passes over a locked item instead of waiting', function (): void {
    $items = StorageItem::query()->where('storage_group_id', $this->fx['group']->id)->orderBy('id')->get();
    StorageItem::query()->whereIn('id', $items->skip(1)->pluck('id'))->update(['status' => StorageItemStatus::Invalid->value]);
    $last = $items->first();

    // Another checkout is reserving the last key right now.
    $this->race->beginTransaction();
    $this->race->select('select id from storage_items where id = ? for update', [$last->id]);

    $this->actingAs($this->fx['buyer'])
        ->post('/checkout', CheckoutFixture::form($this->fx['auto']))
        ->assertSessionHasErrors('quantity');
    expect(Order::query()->count())->toBe(0);

    // It gave up: the key is free again and the next checkout gets it.
    $this->race->rollBack();
    $this->post('/checkout', CheckoutFixture::form($this->fx['auto']))->assertRedirect();

    $order = CheckoutFixture::lastOrder($this->fx['buyer']);
    expect(StorageItem::query()->whereKey($last->id)->value('reserved_for_order_item_id'))->toBe($order->item->id);
});

it('serialises checkouts of manual stock on the offer row, so the last unit is sold once', function (): void {
    DB::table('offers')->where('id', $this->fx['manual']->id)->update(['manual_stock_qty' => 1]);

    // Another checkout holds the offer row and is about to take the last unit.
    $this->race->beginTransaction();
    $this->race->select('select id from offers where id = ? for update', [$this->fx['manual']->id]);
    DB::statement("SET lock_timeout = '300ms'");

    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs($this->fx['buyer'])->post('/checkout', CheckoutFixture::form($this->fx['manual'])))
        ->toThrow(QueryException::class); // waited on the lock instead of overselling

    $this->race->update('update offers set manual_stock_qty = 0 where id = ?', [$this->fx['manual']->id]);
    $this->race->commit();
    DB::statement('SET lock_timeout = 0');
    $this->withExceptionHandling();

    $this->post('/checkout', CheckoutFixture::form($this->fx['manual']))->assertSessionHasErrors('quantity');
    expect(Order::query()->count())->toBe(0)
        ->and(DB::table('offers')->where('id', $this->fx['manual']->id)->value('manual_stock_qty'))->toBe(0);
});

it('stores a webhook event once even when the same event id is inserted twice', function (): void {
    $insert = fn () => DB::table('payment_events')->insert([
        'provider' => 'fake', 'provider_event_id' => 'evt_dup', 'type' => 'payment.captured',
        'payload' => '{}', 'signature_valid' => true, 'received_at' => now(),
    ]);

    $insert();
    expect($insert)->toThrow(QueryException::class)
        ->and(DB::table('payment_events')->where('provider_event_id', 'evt_dup')->count())->toBe(1);
});
