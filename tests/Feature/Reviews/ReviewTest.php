<?php

declare(strict_types=1);

use App\Domains\Announcements\Models\InboxItem;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Points\Support\Points;
use App\Domains\Reviews\Actions\OrderComments;
use App\Domains\Reviews\Exceptions\ReviewException;
use App\Domains\Reviews\Models\OrderComment;
use App\Domains\Reviews\Support\ReviewPresenter;
use App\Domains\Reviews\Support\SellerRatings;
use App\Domains\Sellers\Models\SellerStats;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;

beforeEach(function (): void {
    $this->fx = CheckoutFixture::build();
    $this->buyer = $this->fx['buyer'];
    $this->seller = $this->fx['seller'];
});

function completeFor(array $fx, User $buyer): Order
{
    test()->actingAs($buyer);
    $order = CheckoutFixture::deliveredOrder([...$fx, 'buyer' => $buyer]);

    return app(OrderStateMachine::class)->complete($order, ActorType::Buyer, $buyer);
}

function comment(Order $order, User $buyer, string $rating = 'positive', ?string $body = 'Fast delivery, the code worked right away.'): OrderComment
{
    return app(OrderComments::class)->leave($order, $buyer, ['rating' => $rating, 'score_delivery' => 5, 'score_service' => 4, 'score_description' => 5, 'body' => $body]);
}

it('invites the buyer to comment, lists the order under To comment, and takes one comment with points', function (): void {
    $order = completeFor($this->fx, $this->buyer);
    expect(InboxItem::query()->where('user_id', $this->buyer->id)->where('notification_type', 'comment_invite')->exists())->toBeTrue();

    $this->get('/buy/comments')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Buy/Comments')
        ->has('pending', 1)
        ->where('pending.0.orderId', $order->public_id));
    $this->get("/buy/orders/{$order->public_id}")->assertInertia(fn (Assert $p) => $p->where('can.comment', true));

    $before = Points::balance($this->buyer->id);
    $this->post("/buy/orders/{$order->public_id}/comment", ['rating' => 'positive', 'score_delivery' => 5, 'body' => 'Great seller, very fast.'])->assertRedirect('/buy/comments?tab=commented');
    $this->post("/buy/orders/{$order->public_id}/comment", ['rating' => 'negative'])->assertSessionHasErrors('comment');

    expect(OrderComment::query()->count())->toBe(1)
        ->and(Points::balance($this->buyer->id))->toBe($before + 10)
        ->and(InboxItem::query()->where('user_id', $this->seller->user_id)->where('notification_type', 'comment_received')->exists())->toBeTrue();

    $this->get('/buy/comments')->assertInertia(fn (Assert $p) => $p->has('pending', 0));
    $this->get('/buy/comments?tab=commented')->assertInertia(fn (Assert $p) => $p->has('comments', 1)->where('comments.0.editable', true)->where('scores.periods.all.positive', 1));
});

it('refuses comments before completion, after 30 days, by someone else, and with contact details', function (): void {
    $this->actingAs($this->buyer);
    $delivered = CheckoutFixture::deliveredOrder($this->fx);
    expect(fn () => comment($delivered, $this->buyer))->toThrow(ReviewException::class);

    $order = completeFor($this->fx, $this->buyer);
    expect(fn () => comment($order, User::factory()->create()))->toThrow(ReviewException::class)
        ->and(fn () => comment($order, $this->buyer, body: 'Write me at buyer@example.com'))->toThrow(ReviewException::class);

    $this->travel(31)->days();
    expect(fn () => comment($order, $this->buyer))->toThrow(ReviewException::class);
});

it('lets the buyer edit until the seller replies, and the seller reply once', function (): void {
    $order = completeFor($this->fx, $this->buyer);
    $c = comment($order, $this->buyer, 'neutral');

    $this->put("/buy/comments/{$c->id}", ['rating' => 'positive', 'body' => 'Sorted out quickly, thanks.'])->assertSessionHas('success');
    expect($c->refresh()->rating)->toBe('positive')->and($c->edited_at)->not->toBeNull();

    $this->actingAs($this->seller->user)->post("/seller/comments/{$c->id}/reply", ['reply' => 'Thank you, see you soon!'])->assertSessionHas('success');
    $this->post("/seller/comments/{$c->id}/reply", ['reply' => 'Again'])->assertSessionHasErrors('reply');
    $this->actingAs(User::factory()->create())->post("/seller/comments/{$c->id}/reply", ['reply' => 'x'])->assertRedirect(); // not a seller

    $this->actingAs($this->buyer)->put("/buy/comments/{$c->id}", ['rating' => 'negative'])->assertSessionHasErrors('comment');
    expect($c->refresh()->seller_reply)->toBe('Thank you, see you soon!')->and($c->rating)->toBe('positive');
});

it('computes the positive rate (lifetime and 90 days), the score table and the sub-scores', function (): void {
    foreach (['positive', 'positive', 'positive', 'negative', 'neutral'] as $i => $rating) {
        $buyer = $i === 0 ? $this->buyer : User::factory()->create(['created_at' => now()->subMonth()]);
        comment(completeFor($this->fx, $buyer), $buyer, $rating);
    }

    $stats = SellerStats::query()->where('seller_profile_id', $this->seller->id)->sole();
    expect($stats->positive_rate_bps)->toBe(7500)          // 3 / (3 + 1), neutral not counted
        ->and($stats->positive_rate_90d_bps)->toBe(7500)
        ->and($stats->rating_count)->toBe(5);

    $table = SellerRatings::scoreTable('seller', $this->seller->id);
    expect($table['periods']['7d'])->toBe(['positive' => 3, 'neutral' => 1, 'negative' => 1])
        ->and(SellerRatings::subScores($this->seller->id))->toBe(['delivery' => 5.0, 'service' => 4.0, 'description' => 5.0, 'overall' => 4.7]);

    $this->travel(100)->days();
    app(SellerRatings::class)->refresh($this->seller->id);
    expect($stats->refresh()->positive_rate_90d_bps)->toBeNull()->and($stats->positive_rate_bps)->toBe(7500);

    $this->actingAs($this->seller->user)->get('/seller/comments')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Seller/Comments')
        ->has('comments', 5)
        ->where('comments.0.buyer', fn ($name) => str_contains($name, '***'))
        ->where('subScores.overall', 4.7));
});

it('hides comments from ratings and pages, needs a reason, and features only visible positive ones', function (): void {
    $order = completeFor($this->fx, $this->buyer);
    $c = comment($order, $this->buyer, 'negative', 'Late delivery.');
    $staff = User::factory()->staff(StaffRole::Moderator)->create();
    $actions = app(OrderComments::class);

    expect(fn () => $actions->hide($c, $staff, ''))->toThrow(ReviewException::class);
    $actions->hide($c, $staff, 'Abusive language');
    expect(SellerStats::query()->where('seller_profile_id', $this->seller->id)->value('positive_rate_bps'))->toBeNull()
        ->and(ReviewPresenter::store($this->seller, 'en'))->toBe([])
        ->and(fn () => $actions->feature($c->refresh(), true))->toThrow(ReviewException::class);

    $actions->show($c);
    expect(ReviewPresenter::store($this->seller, 'en'))->toHaveCount(1);
});

it('shows Community ratings: featured first, then recent positive comments with enough text, one per seller', function (): void {
    $order = completeFor($this->fx, $this->buyer);
    comment($order, $this->buyer, 'positive', 'Too short');
    expect(ReviewPresenter::community(4, 'en'))->toBe([]);

    $buyer2 = User::factory()->create(['created_at' => now()->subMonth()]);
    $long = comment(completeFor($this->fx, $buyer2), $buyer2, 'positive', 'Excellent seller, the key arrived in two minutes.');
    $cards = ReviewPresenter::community(4, 'en');
    expect($cards)->toHaveCount(1)->and($cards[0]['id'])->toBe($long->id)->and($cards[0]['stars'])->toBe(5);

    $this->get('/en')->assertOk()->assertInertia(fn (Assert $p) => $p->has('communityRatings', 1));
    $this->get('/en/store/'.$this->seller->slug)->assertInertia(fn (Assert $p) => $p->has('reviews', 2)->where('store.stats.ratingCount', 2));
});

it('opens review moderation to moderators only', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::Moderator)->withTwoFactor()->create())->get('/admin/reviews')->assertOk();
    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create())->get('/admin/reviews')->assertForbidden();
});
