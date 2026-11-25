<?php

declare(strict_types=1);

use App\Domains\Campaigns\Actions\SendMarketingEmail;
use App\Domains\Campaigns\Actions\SettleMarketingEmails;
use App\Domains\Campaigns\Mail\MarketingMail;
use App\Domains\Campaigns\Models\MarketingEmail;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserPreference;
use App\Domains\Ledger\Actions\PostJournal;
use App\Domains\Ledger\Actions\VerifyLedger;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Offers\Models\Offer;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Shared\Support\Settings;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;
use Tests\Support\CouponFixture;
use Tests\Support\Ledger;

beforeEach(function (): void {
    Mail::fake();
    $this->fx = CheckoutFixture::build();
    $this->seller = $this->fx['seller'];

    // Two past buyers with a completed order; the second one turned seller promotions off.
    $this->buyers = [$this->fx['buyer'], User::factory()->create(['created_at' => now()->subMonth()])];
    foreach ($this->buyers as $buyer) {
        $this->actingAs($buyer);
        $order = CheckoutFixture::deliveredOrder([...$this->fx, 'buyer' => $buyer]);
        app(OrderStateMachine::class)->complete($order, ActorType::Buyer, $buyer);
    }
    UserPreference::query()->updateOrCreate(['user_id' => $this->buyers[1]->id], ['seller_promo_emails' => false]);

    $this->actingAs($this->seller->user);
    $this->send = fn (array $o = []) => $this->post('/seller/marketing/emails', [
        'type' => 'limited_discount',
        'offers' => [$this->fx['manual']->public_id],
        'coupon' => null,
        'from' => now()->subDays(30)->toDateString(),
        'to' => now()->toDateString(),
        ...$o,
    ]);
});

function fundAvailable(User $seller, int $cents): void
{
    app(PostJournal::class)->handle(new JournalDraft(
        type: JournalType::ManualAdjustment,
        idempotencyKey: 'TEST:avail:'.$seller->id.':'.$cents,
        lines: [
            [AccountRef::platform(AccountCode::ExpPromotions, 'USD'), $cents],
            [AccountRef::user(AccountCode::SellerAvailable, $seller->id, 'USD'), -$cents],
        ],
    ));
}

it('previews past buyers who allow promotions, with the cost and the caps left', function (): void {
    $this->get('/seller/marketing/emails')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Seller/Marketing/Emails')
        ->where('preview.count', 1)
        ->where('preview.unitMillicents', 100)
        ->where('preview.total', 1)       // 1 × $0.001 rounded up to a cent
        ->where('preview.dailyLeft', 1000)
        ->has('offers', 2));
});

it('rounds the cost up to whole cents', function (): void {
    expect(SendMarketingEmail::cost(1, 100))->toBe(1)
        ->and(SendMarketingEmail::cost(1000, 100))->toBe(100)
        ->and(SendMarketingEmail::cost(1001, 100))->toBe(101)
        ->and(SendMarketingEmail::cost(0, 100))->toBe(0);
});

it('sends to the opted-in buyers only, with the private coupon, and records an unsettled send', function (): void {
    $private = CouponFixture::store($this->seller, ['code' => 'LOYAL20', 'is_public' => false]);

    ($this->send)(['coupon' => $private->public_id])->assertRedirect('/seller/marketing/records');

    $email = MarketingEmail::query()->sole();
    expect($email->recipient_count)->toBe(1)->and($email->status)->toBe('unsettled')->and($email->coupon_id)->toBe($private->id);
    Mail::assertQueued(MarketingMail::class, 1);
    Mail::assertQueued(MarketingMail::class, fn (MarketingMail $m): bool => $m->userId === $this->buyers[0]->id);

    $html = (new MarketingMail($email->id, $this->buyers[0]->id))->render();
    expect($html)->toContain('LOYAL20')->toContain('Coins pack');

    $this->get('/seller/marketing/records')->assertInertia(fn (Assert $p) => $p->has('records', 1)->where('records.0.status', 'unsettled'));
});

it('refuses public coupons, other sellers\' offers and sends over the daily cap', function (): void {
    $public = CouponFixture::store($this->seller);
    ($this->send)(['coupon' => $public->public_id])->assertSessionHasErrors('send');

    $foreign = Offer::factory()->create();
    ($this->send)(['offers' => [$foreign->public_id]])->assertSessionHasErrors('send');

    app(Settings::class)->set('marketing_email_daily_cap', 0);
    ($this->send)()->assertSessionHasErrors('send');

    expect(MarketingEmail::query()->count())->toBe(0);
    Mail::assertNotQueued(MarketingMail::class);
});

it('bills the send the next day from the Available balance (MARKETING_FEE), once', function (): void {
    ($this->send)();
    fundAvailable($this->seller->user, 500);

    // Same day: nothing yet.
    expect(app(SettleMarketingEmails::class)->handle())->toBe(['settled' => 0, 'unsettled' => 0]);

    $this->travel(1)->days();
    expect(app(SettleMarketingEmails::class)->handle())->toBe(['settled' => 1, 'unsettled' => 0])
        ->and(app(SettleMarketingEmails::class)->handle())->toBe(['settled' => 0, 'unsettled' => 0])
        ->and(Ledger::owedBy($this->seller->user, 'SELLER_AVAILABLE'))->toBe(499)
        ->and(Ledger::revenue('REV_MARKETING'))->toBe(1)
        ->and(MarketingEmail::query()->sole()->status)->toBe('settled')
        ->and(app(VerifyLedger::class)->handle())->toBe([]);
});

it('stays unsettled while the Available balance is too low, and is retried', function (): void {
    ($this->send)();
    $this->travel(1)->days();

    expect(app(SettleMarketingEmails::class)->handle())->toBe(['settled' => 0, 'unsettled' => 1])
        ->and(MarketingEmail::query()->sole()->status)->toBe('unsettled');

    fundAvailable($this->seller->user, 100);
    expect(app(SettleMarketingEmails::class)->handle())->toBe(['settled' => 1, 'unsettled' => 0]);
});
