<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\PostJournal;
use App\Domains\Ledger\Actions\VerifyLedger;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Ledger\Exceptions\BalanceOverdrawn;
use App\Domains\Ledger\Exceptions\LedgerException;
use App\Domains\Ledger\Models\LedgerJournal;
use App\Domains\Ledger\Models\LedgerPosting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Ledger;

beforeEach(function (): void {
    $this->post = app(PostJournal::class);
    $this->user = User::factory()->create();
    $this->credits = AccountRef::user(AccountCode::BuyerCredits, $this->user->id, 'USD');
    $this->escrow = AccountRef::platform(AccountCode::OrderEscrow, 'USD');
    $this->promo = AccountRef::platform(AccountCode::ExpPromotions, 'USD');
});

function draft(string $key, array $lines, JournalType $type = JournalType::PromoCredit, array $noOverdraw = []): JournalDraft
{
    return new JournalDraft($type, $key, $lines, noOverdraw: $noOverdraw);
}

it('posts a balanced journal and keeps balances as the sum of postings', function (): void {
    $journal = $this->post->handle(draft('k1', [[$this->promo, 2500], [$this->credits, -2500]]));

    expect($journal->postings()->count())->toBe(2)
        ->and(Ledger::credits($this->user))->toBe(2500)
        ->and(app(VerifyLedger::class)->handle())->toBe([]);
});

it('is idempotent per key, and refuses a key reused by another journal type', function (): void {
    $first = $this->post->handle(draft('same', [[$this->promo, 100], [$this->credits, -100]]));
    $again = $this->post->handle(draft('same', [[$this->promo, 999], [$this->credits, -999]]));

    expect($again->id)->toBe($first->id)
        ->and(Ledger::credits($this->user))->toBe(100)
        ->and(fn () => $this->post->handle(draft('same', [[$this->promo, 1], [$this->credits, -1]], JournalType::ManualAdjustment)))->toThrow(LedgerException::class);
});

it('refuses unbalanced, empty and per-currency unbalanced journals, posting nothing', function (array $lines): void {
    expect(fn () => $this->post->handle(draft('bad', $lines)))->toThrow(LedgerException::class)
        ->and(LedgerJournal::query()->count())->toBe(0)
        ->and(LedgerPosting::query()->count())->toBe(0);
})->with([
    'unbalanced' => fn () => [[test()->promo, 100], [test()->credits, -99]],
    'empty' => fn () => [],
    'only zeros' => fn () => [[test()->promo, 0], [test()->credits, 0]],
    'balanced overall, not per currency' => fn () => [[test()->promo, 100], [AccountRef::user(AccountCode::BuyerCredits, test()->user->id, 'EUR'), -100]],
]);

it('merges lines on the same account', function (): void {
    $journal = $this->post->handle(draft('m', [[$this->promo, 300], [$this->credits, -100], [$this->credits, -200]]));

    expect($journal->postings()->count())->toBe(2)->and(Ledger::credits($this->user))->toBe(300);
});

it('never lets a spend overdraw a user balance (invariant 4.3 #2)', function (): void {
    Ledger::fundCredits($this->user, 500);

    expect(fn () => $this->post->handle(draft('spend', [[$this->credits, 501], [$this->escrow, -501]], JournalType::CreditsSpent, [$this->credits])))
        ->toThrow(BalanceOverdrawn::class)
        ->and(Ledger::credits($this->user))->toBe(500)
        ->and(LedgerJournal::query()->where('idempotency_key', 'spend')->exists())->toBeFalse();

    $this->post->handle(draft('spend', [[$this->credits, 500], [$this->escrow, -500]], JournalType::CreditsSpent, [$this->credits]));
    expect(Ledger::credits($this->user))->toBe(0);
});

it('keeps journals and postings append-only', function (): void {
    $journal = $this->post->handle(draft('a', [[$this->promo, 1], [$this->credits, -1]]));
    $posting = $journal->postings()->firstOrFail();

    expect(fn () => $journal->forceFill(['description' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => $posting->delete())->toThrow(LogicException::class);
});

it('ledger:verify passes on a clean ledger and reports tampering', function (): void {
    Ledger::fundCredits($this->user, 700);
    expect(Artisan::call('ledger:verify'))->toBe(0);

    DB::table('ledger_balances')->update(['balance' => DB::raw('balance + 1')]);

    expect(Artisan::call('ledger:verify'))->toBe(1)
        ->and(app(VerifyLedger::class)->handle())->toHaveCount(2);
});
