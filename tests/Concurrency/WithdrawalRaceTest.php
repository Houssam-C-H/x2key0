<?php

declare(strict_types=1);

use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Support\Accounts;
use App\Domains\Payouts\Actions\RequestWithdrawal;
use App\Domains\Payouts\Exceptions\PayoutException;
use App\Domains\Payouts\Models\WithdrawalRequest;
use Illuminate\Support\Facades\DB;
use Tests\Support\Payouts;

/*
| Acceptance (spec Part 25 M13): concurrent withdrawals are safe. Requests take the seller's balance row lock
| (PostJournal) and check the no-overdraw guard after their posting, so whatever another connection took first is
| seen and the total withdrawn can never exceed the balance.
*/

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Lock contention needs PostgreSQL.');
    }

    config(['database.connections.race' => config('database.connections.pgsql')]);
    ['seller' => $this->seller, 'method' => $this->method] = Payouts::seller(10000);
    $this->race = DB::connection('race');
});

afterEach(function (): void {
    if (isset($this->race) && $this->race->transactionLevel() > 0) {
        $this->race->rollBack();
    }
    DB::purge('race');
});

it('sees what a parallel withdrawal (another connection) took and refuses to overdraw', function (): void {
    $available = AccountRef::user(AccountCode::SellerAvailable, $this->seller->id, 'USD');
    $accountId = Accounts::id($available);

    // A parallel withdrawal has taken $80.00 and still holds the balance row.
    $this->race->beginTransaction();
    $this->race->select('select * from ledger_balances where account_id = ? for update', [$accountId]);
    $this->race->update('update ledger_balances set balance = balance + 8000 where account_id = ?', [$accountId]);

    $this->race->commit();

    expect(fn () => app(RequestWithdrawal::class)->handle($this->seller, $this->method->id, 5000, 'race-1'))
        ->toThrow(PayoutException::class);
    expect(WithdrawalRequest::query()->count())->toBe(0)
        ->and(Accounts::owed($available))->toBe(2000);
});

it('serialises many requests: the total withdrawn never exceeds the balance', function (): void {
    $ok = 0;
    foreach (range(1, 6) as $i) {
        try {
            app(RequestWithdrawal::class)->handle($this->seller, $this->method->id, 2500, "burst-{$i}");
            $ok++;
        } catch (PayoutException) {
        }
    }

    expect($ok)->toBe(4)
        ->and(Accounts::owed(AccountRef::user(AccountCode::SellerAvailable, $this->seller->id, 'USD')))->toBe(0)
        ->and((int) WithdrawalRequest::query()->sum('amount'))->toBe(10000);
});
