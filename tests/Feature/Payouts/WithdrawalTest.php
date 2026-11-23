<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Invoices\Actions\IssueInvoices;
use App\Domains\Invoices\Models\Invoice;
use App\Domains\Ledger\Actions\VerifyLedger;
use App\Domains\Ledger\Models\LedgerJournal;
use App\Domains\Payouts\Actions\ManagePayoutMethods;
use App\Domains\Payouts\Actions\RequestWithdrawal;
use App\Domains\Payouts\Enums\WithdrawalStatus;
use App\Domains\Payouts\Exceptions\PayoutException;
use App\Domains\Payouts\Models\PayoutMethod;
use App\Domains\Payouts\Models\WithdrawalRequest;
use App\Domains\Payouts\StateMachines\WithdrawalStateMachine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Ledger;
use Tests\Support\Payouts;

beforeEach(function (): void {
    ['seller' => $this->seller, 'method' => $this->method] = Payouts::seller(10000); // $100.00 available
    $this->finance = User::factory()->staff(StaffRole::Finance)->create();
    $this->request = fn (int $cents, ?string $key = null) => app(RequestWithdrawal::class)->handle($this->seller, $this->method->id, $cents, $key ?? (string) str()->ulid());
    $this->machine = app(WithdrawalStateMachine::class);
    $this->owed = fn (string $code) => Ledger::owedBy($this->seller, $code);
    $this->clean = fn () => expect(app(VerifyLedger::class)->handle())->toBe([]);
});

it('requests: money leaves Available into in-transit at once; fee = max(platform minimum, method fee)', function (): void {
    $w = ($this->request)(5000); // PayPal 2.99 % + $0.99 → 150 + 99 = 249

    expect($w->status)->toBe(WithdrawalStatus::Requested)
        ->and($w->fee_amount)->toBe(249)
        ->and($w->payout_amount)->toBe(4751)
        ->and($w->payout_label)->toBe('PayPal · se•••@example.com')
        ->and(($this->owed)('SELLER_AVAILABLE'))->toBe(5000)
        ->and(($this->owed)('PAYOUT_IN_TRANSIT'))->toBe(5000)
        ->and($w->history()->sole()->to_status)->toBe(WithdrawalStatus::Requested);
    ($this->clean)();
});

it('approve → processing → paid: bank out, payout fee revenue, voucher issued with a sequential number', function (): void {
    $w = ($this->request)(5000);
    $this->machine->approve($w, $this->finance);
    $this->machine->process($w, $this->finance);
    $paid = $this->machine->pay($w, $this->finance, 'PP-123');

    $voucher = Invoice::query()->where('type', Invoice::VOUCHER)->sole();
    expect($paid->status)->toBe(WithdrawalStatus::Paid)
        ->and($paid->provider_ref)->toBe('PP-123')
        ->and(($this->owed)('PAYOUT_IN_TRANSIT'))->toBe(0)
        ->and(Ledger::revenue('REV_PAYOUT_FEE'))->toBe(249)
        ->and($voucher->number)->toBe('X2W-'.now()->year.'-000001')
        ->and($voucher->total)->toBe(4751)
        ->and($voucher->source_id)->toBe($w->id)
        ->and($paid->history()->pluck('to_status')->map->value->all())->toBe(['requested', 'approved', 'processing', 'paid']);

    // The voucher is idempotent and immutable.
    expect(app(IssueInvoices::class)->voucher($paid)->id)->toBe($voucher->id)
        ->and(fn () => $voucher->forceFill(['total' => 1])->save())->toThrow(LogicException::class);
    ($this->clean)();
});

it('reject, cancel and failed all return the money to Available', function (): void {
    $a = ($this->request)(2000);
    $b = ($this->request)(2000);
    $c = ($this->request)(2000);

    $this->machine->reject($a, $this->finance, 'Account name does not match');
    $this->machine->cancel($b, $this->seller);
    $this->machine->approve($c, $this->finance);
    $this->machine->process($c, $this->finance);
    $this->machine->fail($c, $this->finance, 'PayPal account limited');

    expect(($this->owed)('SELLER_AVAILABLE'))->toBe(10000)
        ->and(($this->owed)('PAYOUT_IN_TRANSIT'))->toBe(0)
        ->and($a->refresh()->remarks)->toBe('Account name does not match')
        ->and($c->refresh()->failure_reason)->toBe('PayPal account limited')
        ->and(LedgerJournal::query()->whereIn('type', ['WITHDRAWAL_RELEASED', 'WITHDRAWAL_FAILED'])->count())->toBe(3);
    ($this->clean)();
});

it('never withdraws more than is available; a double submit returns the same request', function (): void {
    ($this->request)(6000);
    expect(fn () => ($this->request)(6000))->toThrow(PayoutException::class, __('payout.error.insufficient'));

    $first = ($this->request)(3000, 'same-key');
    $again = ($this->request)(3000, 'same-key');
    expect($again->id)->toBe($first->id)
        ->and(WithdrawalRequest::query()->count())->toBe(2)
        ->and(($this->owed)('SELLER_AVAILABLE'))->toBe(1000);
    ($this->clean)();
});

it('refuses without identity, 2FA, payouts enabled or an open store; below the minimum or fee', function (): void {
    expect(fn () => ($this->request)(500))->toThrow(PayoutException::class); // minimum $10.00

    $this->seller->sellerProfile->forceFill(['payouts_enabled' => false])->save();
    expect(fn () => ($this->request)(5000))->toThrow(PayoutException::class, __('payout.blocked.payouts_enabled'));

    $this->seller->sellerProfile->forceFill(['payouts_enabled' => true])->save();
    $this->seller->forceFill(['two_factor_confirmed_at' => null])->save();
    expect(fn () => ($this->request)(5000))->toThrow(PayoutException::class, __('payout.blocked.two_factor'));
});

it('state rules: staff never act on their own withdrawal; paid and failed are final', function (): void {
    $w = ($this->request)(2000);
    $this->seller->assignRole(StaffRole::Finance->value);
    expect(fn () => $this->machine->approve($w, $this->seller))->toThrow(PayoutException::class);

    expect(fn () => $this->machine->pay($w, $this->finance, 'x'))->toThrow(PayoutException::class)
        ->and(Gate::forUser($this->finance)->allows('update', $w))->toBeTrue()
        ->and(Gate::forUser(User::factory()->staff(StaffRole::Support)->create())->allows('viewAny', WithdrawalRequest::class))->toBeFalse();
});

it('payout methods: details encrypted at rest, masked everywhere, removal blocked while a withdrawal is open', function (): void {
    $raw = (string) DB::table('payout_methods')->where('id', $this->method->id)->value('details_encrypted');
    expect($raw)->not->toContain('seller@example.com')
        ->and($this->method->toArray())->not->toHaveKey('details_encrypted');

    $bank = app(ManagePayoutMethods::class)->add($this->seller, 'bank_transfer', 'USD', ['account_holder' => 'A Seller', 'iban' => 'GB33 BUKB 2020 1555 5555 55', 'swift' => 'BUKBGB22']);
    expect($bank->masked_label)->toBe('Bank transfer (SWIFT/SEPA) · ••••5555');
    expect(fn () => app(ManagePayoutMethods::class)->add($this->seller, 'bank_transfer', 'USD', ['account_holder' => 'x']))->toThrow(PayoutException::class);

    ($this->request)(2000);
    expect(fn () => app(ManagePayoutMethods::class)->remove($this->seller, $this->method))->toThrow(PayoutException::class);
    app(ManagePayoutMethods::class)->remove($this->seller, $bank);
    expect(PayoutMethod::query()->usable()->where('user_id', $this->seller->id)->count())->toBe(1);
});

it('issues monthly service invoices with commission and payout fees, once', function (): void {
    $w = ($this->request)(5000);
    $this->machine->approve($w, $this->finance);
    $this->machine->process($w, $this->finance);
    $this->machine->pay($w, $this->finance, 'PP-1');

    $this->travel(1)->months();
    Artisan::call('invoices:issue-monthly');
    Artisan::call('invoices:issue-monthly');

    $invoice = Invoice::query()->where('type', Invoice::SERVICE)->sole();
    expect($invoice->number)->toStartWith('X2S-')
        ->and($invoice->total)->toBe(249)
        ->and($invoice->user_id)->toBe($this->seller->id);
});

it('HTTP: wizard needs a recent password; records, methods, invoices and info pages', function (): void {
    $this->actingAs($this->seller)->get('/seller/withdrawals')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Seller/Withdrawals/Index')
        ->where('balances.available', 10000)
        ->where('blockers', [])
        ->has('methods', 1));

    $this->post('/seller/withdrawals', ['method' => $this->method->id, 'amount' => '25.00', 'idempotency_key' => 'k1'])->assertRedirect('/user/confirm-password');

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->post('/seller/withdrawals', ['method' => $this->method->id, 'amount' => '25.00', 'idempotency_key' => 'k1'])
        ->assertRedirect('/seller/withdrawals');
    expect(WithdrawalRequest::query()->sole()->amount)->toBe(2500);

    $this->get('/seller/withdrawals/methods')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Seller/Withdrawals/Methods')->has('catalog', 4));

    $this->put('/invoices/info', ['legal_name' => 'Pixel Vault SARL', 'tax_id' => 'MA123456', 'line1' => '1 Rue X', 'city' => 'Casablanca', 'country' => 'ma', 'email' => 'billing@example.com'])->assertSessionHasNoErrors();
    expect(DB::table('invoice_profiles')->value('tax_id_encrypted'))->not->toContain('MA123456');

    $w = WithdrawalRequest::query()->sole();
    $this->machine->approve($w, $this->finance);
    $this->machine->process($w, $this->finance);
    $this->machine->pay($w, $this->finance, 'PP-9');
    $voucher = Invoice::query()->sole();

    $this->get('/invoices/vouchers')->assertInertia(fn (Assert $page) => $page->component('Invoices/Index')->has('invoices.data', 1));
    $this->get($voucher->path())->assertOk()->assertSee('Pixel Vault SARL')->assertSee($voucher->number);
    $this->actingAs(User::factory()->create())->get($voucher->path())->assertNotFound();
    $this->actingAs($this->finance)->get($voucher->path())->assertOk();
});

it('opens the finance admin screens', function (string $path): void {
    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create())->get($path)->assertOk();
})->with(['/admin/withdrawals', '/admin/payout-methods', '/admin/invoices']);
