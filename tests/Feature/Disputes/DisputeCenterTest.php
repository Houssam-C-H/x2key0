<?php

declare(strict_types=1);

use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Disputes\Models\DisputeMessage;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Enums\OrderStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;

beforeEach(function (): void {
    Storage::fake('local');
    $this->fx = CheckoutFixture::build();
    $this->buyer = $this->fx['buyer'];
    $this->sellerUser = $this->fx['seller']->user;
    $this->order = CheckoutFixture::deliveredOrder($this->fx);
});

it('shows "open a dispute" on the order and opens one with evidence over HTTP', function (): void {
    $this->actingAs($this->buyer)->get("/buy/orders/{$this->order->public_id}")
        ->assertInertia(fn (Assert $page) => $page->where('dispute.canOpen', true)->where('dispute.case', null));

    $this->get("/orders/{$this->order->public_id}/disputes/new")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Disputes/Create')
        ->where('role', 'buyer')
        ->where('order.kept', 1));

    $this->post("/orders/{$this->order->public_id}/disputes", [
        'reason' => 'not_working',
        'resolution' => 'refund_full',
        'qty' => 1,
        'description' => 'The coins were credited then removed. Call me on +33 6 12 34 56 78.',
        'files' => [UploadedFile::fake()->image('proof.png', 400, 300)],
    ])->assertRedirect();

    $dispute = Dispute::query()->sole();
    expect($dispute->evidence()->count())->toBe(1);
    Storage::disk('local')->assertExists($dispute->evidence()->sole()->file_path);

    // The seller sees the case with the phone number masked; the buyer sees their own text.
    $this->actingAs($this->sellerUser)->get($dispute->path())->assertInertia(fn (Assert $page) => $page
        ->component('Disputes/Show')
        ->where('dispute.viewer', 'seller')
        ->where('dispute.actionRequired', true)
        ->where('can.accept', true)
        ->where('can.withdraw', false)
        ->where('dispute.description', fn (string $d): bool => ! str_contains($d, '12 34 56 78')));

    $this->get("{$dispute->path()}/evidence/{$dispute->evidence()->sole()->id}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs(User::factory()->create())->get("{$dispute->path()}/evidence/{$dispute->evidence()->sole()->id}")->assertNotFound();
});

it('requires evidence for "not working" and refuses a second case', function (): void {
    $this->actingAs($this->buyer)->post("/orders/{$this->order->public_id}/disputes", [
        'reason' => 'not_working', 'resolution' => 'refund_full', 'qty' => 1, 'description' => 'It does not work at all.',
    ])->assertSessionHasErrors('dispute');

    $payload = ['reason' => 'not_as_described', 'resolution' => 'refund_full', 'qty' => 1, 'description' => 'Wrong amount of coins.'];
    $this->post("/orders/{$this->order->public_id}/disputes", $payload)->assertRedirect();
    $this->get("/orders/{$this->order->public_id}/disputes/new")->assertRedirect(Dispute::query()->sole()->path());
    $this->post("/orders/{$this->order->public_id}/disputes", $payload)->assertSessionHasErrors('dispute');
});

it('seller replies, proposes; buyer accepts; lists and counters follow', function (): void {
    $this->actingAs($this->buyer)->post("/orders/{$this->order->public_id}/disputes", [
        'reason' => 'not_as_described', 'resolution' => 'refund_full', 'qty' => 1, 'description' => 'Wrong amount of coins.',
    ]);
    $dispute = Dispute::query()->sole();

    $this->actingAs($this->sellerUser)->post("{$dispute->path()}/messages", ['body' => 'Let me check, add me on telegram: @keys_shop'])->assertSessionHasNoErrors();
    expect($dispute->refresh()->status)->toBe(DisputeStatus::Negotiating)
        ->and(DisputeMessage::query()->where('author_role', 'seller')->sole()->body_redacted)->not->toContain('keys_shop');

    $this->post("{$dispute->path()}/propose", ['resolution' => 'redelivery', 'qty' => 1])->assertSessionHasNoErrors();

    $this->actingAs($this->buyer)->get('/disputes')->assertInertia(fn (Assert $page) => $page
        ->component('Disputes/Index')
        ->where('counts.open', 1)
        ->where('rows.0.actionRequired', true));
    $this->get('/disputes?filter=response_required')->assertInertia(fn (Assert $page) => $page->has('rows', 1));

    $this->post("{$dispute->path()}/accept")->assertSessionHasNoErrors();
    expect($dispute->refresh()->status)->toBe(DisputeStatus::Resolved)
        ->and($this->order->refresh()->status)->toBe(OrderStatus::Delivering);

    $this->get('/disputes?status=closed')->assertInertia(fn (Assert $page) => $page->where('counts.closed', 1)->has('rows', 1));
    $this->actingAs($this->sellerUser)->get('/disputes?as=seller')->assertInertia(fn (Assert $page) => $page->where('counts.closed', 1));
    $this->get('/disputes/stats?as=seller')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Disputes/Stats')
        ->where('total', 1)
        ->where('rows.0.disputes', 1)
        ->where('rows.0.rateBps', 10000));
});

it('escalation needs an answer first; staff decide from the admin queue', function (): void {
    $this->actingAs($this->buyer)->post("/orders/{$this->order->public_id}/disputes", [
        'reason' => 'not_as_described', 'resolution' => 'refund_full', 'qty' => 1, 'description' => 'Wrong amount of coins.',
    ]);
    $dispute = Dispute::query()->sole();

    $this->post("{$dispute->path()}/escalate")->assertSessionHasErrors('dispute');
    $this->actingAs($this->sellerUser)->post("{$dispute->path()}/messages", ['body' => 'Delivered as listed.']);
    $this->post("{$dispute->path()}/escalate")->assertSessionHasNoErrors();
    expect($dispute->refresh()->status)->toBe(DisputeStatus::Escalated);

    $this->actingAs(User::factory()->staff(StaffRole::Support)->withTwoFactor()->create())->get('/admin/disputes')->assertOk();
    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create())->get('/admin/chargebacks')->assertOk();
});
