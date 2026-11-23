<?php

declare(strict_types=1);

use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Support\Accounts;
use App\Domains\Orders\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CheckoutFixture;
use Tests\Support\Ledger;

/*
| Ledger guarantees enforced by PostgreSQL itself (spec 5.12: deferred zero-sum trigger, append-only), and a
| real concurrent credits spend. Committed rows (DatabaseTruncation), a second connection holds locks.
*/

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Ledger triggers and lock contention need PostgreSQL.');
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

it('refuses at commit a journal that does not sum to zero, even when written around PostJournal', function (): void {
    $a = Accounts::id(AccountRef::platform(AccountCode::ExpPromotions, 'USD'));

    expect(fn () => DB::transaction(function () use ($a): void {
        $journal = DB::table('ledger_journals')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'type' => 'MANUAL_ADJUSTMENT', 'idempotency_key' => 'raw', 'posted_at' => now()]);
        DB::table('ledger_postings')->insert(['journal_id' => $journal, 'account_id' => $a, 'amount' => 100, 'currency' => 'USD', 'created_at' => now()]);
    }))->toThrow(PDOException::class, 'does not sum to zero'); // raised by the deferred trigger at COMMIT

    expect(DB::table('ledger_journals')->count())->toBe(0);
});

it('refuses to update or delete journals and postings', function (): void {
    Ledger::fundCredits($this->fx['buyer'], 500);

    expect(fn () => DB::table('ledger_postings')->update(['amount' => 1]))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('ledger_journals')->delete())->toThrow(QueryException::class, 'append-only')
        ->and(Ledger::credits($this->fx['buyer']))->toBe(500);
});

it('serialises concurrent credits spends on the balance row, so credits are never spent twice', function (): void {
    Ledger::fundCredits($this->fx['buyer'], 1500);
    $account = Accounts::id(AccountRef::user(AccountCode::BuyerCredits, $this->fx['buyer']->id, 'USD'));

    // Another checkout is spending this buyer's credits right now.
    $this->race->beginTransaction();
    $this->race->select('select account_id from ledger_balances where account_id = ? for update', [$account]);
    DB::statement("SET lock_timeout = '300ms'");

    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs($this->fx['buyer'])->post('/checkout', CheckoutFixture::form($this->fx['manual'], ['use_credits' => true, 'method' => null])))
        ->toThrow(QueryException::class); // waited for the other spend instead of reading a stale balance

    // The other spend took (almost) everything and committed.
    $this->race->update('update ledger_balances set balance = balance + 1400 where account_id = ?', [$account]);
    $this->race->commit();
    DB::statement('SET lock_timeout = 0');
    $this->withExceptionHandling();

    // Our checkout sees $1.00 left: the credits part no longer covers the order.
    $this->post('/checkout', CheckoutFixture::form($this->fx['manual'], ['use_credits' => true, 'method' => null]))->assertSessionHasErrors();
    expect(Order::query()->count())->toBe(0);
});
