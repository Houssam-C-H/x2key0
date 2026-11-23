<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Actions\OpenConversation;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Messaging\Models\Conversation;
use App\Domains\Messaging\Models\Message;
use App\Domains\Messaging\Support\Presence;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Risk\Models\RiskFlag;
use App\Domains\Sellers\Models\SellerProfile;
use App\Shared\Support\ContactInfo;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;

beforeEach(function (): void {
    $this->fx = CheckoutFixture::build();
    $this->buyer = $this->fx['buyer'];
    $this->seller = $this->fx['seller'];
    $this->sellerUser = $this->seller->user;
});

function preSale(User $buyer, SellerProfile $seller): Conversation
{
    return app(OpenConversation::class)->preSale($buyer, $seller) ?? throw new RuntimeException('no chat');
}

it('redacts e-mails, links, phones and messenger handles in English, French and Arabic', function (string $text): void {
    expect(ContactInfo::redact($text))->not->toBe($text)
        ->and(ContactInfo::found($text) || ContactInfo::redact($text) !== $text)->toBeTrue();
})->with([
    'email' => 'write me at john.doe@example.com please',
    'spelled email en' => 'john dot doe [at] example [dot] com',
    'link' => 'pay here https://evil.example/pay',
    'domain' => 'go to cheapkeys.shop now',
    'phone' => 'call +1 415 555 0132 tonight',
    'whatsapp' => 'add me on whatsapp: 33612345678',
    'telegram link' => 'join t.me/cheapkeys',
    'fr phone' => 'mon numéro: 06 12 34 56 78',
    'fr arobase' => 'ecris moi: jean arobase gmail point com',
    'fr whatsapp' => 'contactez moi sur whatsapp au 0612345678',
    'ar phone' => 'اتصل بي على ٠٦١٢٣٤٥٦٧٨',
    'ar whatsapp' => 'كلمني على واتساب: ahmed_keys',
    'ar telegram' => 'تيليجرام: @ahmed_keys',
]);

it('leaves ordinary text alone', function (string $text): void {
    expect(ContactInfo::redact($text))->toBe($text);
})->with([
    'hello, is this key still available?',
    'Bonjour, la clé est-elle encore disponible ?',
    'هل المفتاح متوفر؟',
    'I need 2 units for order X2K2611230001',
    'delivery in 10 minutes, thanks',
]);

it('hides contact details from the counterparty but shows the sender the original with a notice', function (): void {
    $chat = preSale($this->buyer, $this->seller);
    app(SendMessage::class)->handle($chat, $this->buyer, 'email me: buyer@example.com thanks');

    $this->actingAs($this->sellerUser)->get("/chat/{$chat->public_id}")->assertInertia(fn (Assert $p) => $p
        ->where('messages.0.text', fn ($t) => str_contains($t, '••••') && ! str_contains($t, 'buyer@example.com'))
        ->where('messages.0.hidden', false));

    $this->actingAs($this->buyer)->get("/chat/{$chat->public_id}")->assertInertia(fn (Assert $p) => $p
        ->where('messages.0.text', 'email me: buyer@example.com thanks')
        ->where('messages.0.hidden', true));

    $row = Message::query()->sole();
    expect($row->body)->toContain('buyer@example.com')->and($row->flags)->toBe(['contact_info' => true]);
});

it('raises a risk flag after repeated contact-info messages, once', function (): void {
    $chat = preSale($this->buyer, $this->seller);
    foreach (range(1, 4) as $i) {
        app(SendMessage::class)->handle($chat, $this->buyer, "call 0612345{$i}78 now");
    }

    expect(RiskFlag::query()->where('rule', 'chat_contact_info')->where('subject_id', $this->buyer->id)->count())->toBe(1);
});

it('starts a pre-sale chat once per buyer and store, and refuses self-chat and hidden stores', function (): void {
    $this->actingAs($this->buyer)->post("/chat/store/{$this->seller->slug}")->assertRedirectContains('/chat/');
    $this->post("/chat/store/{$this->seller->slug}");
    expect(Conversation::query()->count())->toBe(1);

    $this->actingAs($this->sellerUser)->post("/chat/store/{$this->seller->slug}")->assertForbidden();
    expect(app(OpenConversation::class)->preSale($this->buyer, SellerProfile::factory()->create(['status' => 'inactive'])))->toBeNull();
});

it('creates the order conversation when the order is paid, with a system message', function (): void {
    $this->actingAs($this->buyer)->post('/checkout', CheckoutFixture::form($this->fx['manual']));
    $order = CheckoutFixture::lastOrder($this->buyer);
    expect(Conversation::query()->count())->toBe(0);

    CheckoutFixture::webhook($order, PaymentStatus::Captured);
    CheckoutFixture::webhook($order, PaymentStatus::Captured); // replay: still one chat

    $chat = Conversation::query()->sole();
    expect($chat->type)->toBe('order')->and($chat->order_id)->toBe($order->id);

    $this->actingAs($this->buyer)->get("/chat/{$chat->public_id}")->assertInertia(fn (Assert $p) => $p
        ->where('messages.0.kind', 'system')
        ->where('messages.0.text', "Order {$order->number} is paid. Use this chat for anything about the order."));

    // Both order pages open it.
    $this->post("/chat/order/{$order->public_id}")->assertRedirect("/chat/{$chat->public_id}");
    $this->actingAs($this->sellerUser)->post("/chat/order/{$order->public_id}")->assertRedirect("/chat/{$chat->public_id}");
    $this->actingAs(User::factory()->create())->post("/chat/order/{$order->public_id}")->assertNotFound();
});

it('keeps conversations private to their two participants', function (): void {
    $chat = preSale($this->buyer, $this->seller);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get("/chat/{$chat->public_id}")->assertNotFound();
    $this->get("/chat/{$chat->public_id}/poll")->assertNotFound();
    $this->postJson("/chat/{$chat->public_id}/messages", ['body' => 'hi'])->assertNotFound();
    expect(Message::query()->count())->toBe(0);
});

it('validates message length and blank messages', function (): void {
    $chat = preSale($this->buyer, $this->seller);
    $this->actingAs($this->buyer);

    $this->postJson("/chat/{$chat->public_id}/messages", ['body' => '   '])->assertUnprocessable();
    $this->postJson("/chat/{$chat->public_id}/messages", ['body' => str_repeat('a', SendMessage::MAX_LENGTH + 1)])->assertUnprocessable();
    $this->postJson("/chat/{$chat->public_id}/messages", ['body' => 'Is it available?'])->assertOk();
    expect(Message::query()->count())->toBe(1);
});

it('counts unread conversations for the badge and clears them when the thread is opened', function (): void {
    $chat = preSale($this->buyer, $this->seller);
    app(SendMessage::class)->handle($chat, $this->buyer, 'Hello?');

    $this->actingAs($this->sellerUser)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('chat.unread', 1));
    $this->get('/chat')->assertInertia(fn (Assert $p) => $p->where('conversations.0.unread', 1)->where('conversations.0.with.name', $this->buyer->name));

    $this->get("/chat/{$chat->public_id}")->assertOk();
    $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('chat.unread', 0));

    // The sender never has their own message unread.
    $this->actingAs($this->buyer)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('chat.unread', 0));
});

it('polls the thread and presence as JSON', function (): void {
    $chat = preSale($this->buyer, $this->seller);
    app(SendMessage::class)->handle($chat, $this->buyer, 'Hello?');
    $this->sellerUser->forceFill(['last_seen_at' => now()])->save();

    $this->actingAs($this->buyer)->getJson("/chat/{$chat->public_id}/poll")->assertOk()->assertJsonPath('messages.0.text', 'Hello?')->assertJsonPath('online', true);
});

it('answers with the seller\'s first-message auto-reply once, and off-hours replies sparingly', function (): void {
    $this->seller->forceFill(['auto_reply' => ['first_message' => 'Hi! I reply within the hour.', 'off_hours_message' => 'We are away.', 'hours_start' => '09:00', 'hours_end' => '17:00']])->save();
    $chat = preSale($this->buyer, $this->seller);
    $send = app(SendMessage::class);

    $this->travelTo(now()->setTime(12, 0));
    $send->handle($chat, $this->buyer, 'Hello');
    $send->handle($chat, $this->buyer, 'Anyone there?');
    expect(Message::query()->where('kind', 'like', 'auto%')->pluck('body')->all())->toBe(['Hi! I reply within the hour.']);

    $this->travelTo(now()->setTime(23, 0));
    $send->handle($chat, $this->buyer, 'Hello again');
    $send->handle($chat, $this->buyer, 'Still there?');
    expect(Message::query()->where('kind', 'like', 'auto%')->orderBy('id')->pluck('body')->all())->toBe(['Hi! I reply within the hour.', 'We are away.']);

    // The seller's own messages never trigger an auto-reply.
    $before = Message::query()->count();
    $send->handle($chat, $this->sellerUser, 'Back now');
    expect(Message::query()->count())->toBe($before + 1);
});

it('treats working hours that wrap past midnight', function (): void {
    $this->travelTo(now()->setTime(23, 30));
    expect(SendMessage::withinHours('22:00', '06:00'))->toBeTrue()
        ->and(SendMessage::withinHours('09:00', '17:00'))->toBeFalse()
        ->and(SendMessage::withinHours(null, null))->toBeTrue();
});

it('is online while seen within the threshold', function (): void {
    $presence = app(Presence::class);
    expect($presence->online(now()->subSeconds(100)))->toBeTrue()
        ->and($presence->online(now()->subSeconds(121)))->toBeFalse()
        ->and($presence->online(null))->toBeFalse();
});

it('shows the online dot on the public store page', function (): void {
    $this->sellerUser->forceFill(['last_seen_at' => now()])->save();
    $this->get("/en/store/{$this->seller->slug}")->assertInertia(fn (Assert $p) => $p->where('store.online', true));

    $this->sellerUser->forceFill(['last_seen_at' => now()->subHour()])->save();
    $this->get("/en/store/{$this->seller->slug}")->assertInertia(fn (Assert $p) => $p->where('store.online', false));
});
