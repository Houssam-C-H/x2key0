<?php

declare(strict_types=1);

use App\Domains\Announcements\Mail\NotificationMail;
use App\Domains\Announcements\Support\Notifier;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserPreference;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * E-mail copies of important notifications: branded, in the user's language, with preferences.
 */

beforeEach(function (): void {
    Mail::fake();
    $this->user = User::factory()->create(['locale' => 'en']);
    UserPreference::query()->firstOrCreate(['user_id' => $this->user->id]);
});

it('e-mails important notifications and keeps minor ones in the inbox only', function (): void {
    Notifier::notify($this->user->id, 'order_delivered', 'order', ['number' => 'X2K-1', 'href' => '/buy/orders/abc']);
    Notifier::notify($this->user->id, 'comment_received', 'order', ['number' => 'X2K-1']);

    Mail::assertQueued(NotificationMail::class, 1);
    Mail::assertQueued(NotificationMail::class, fn (NotificationMail $m): bool => $m->hasTo($this->user->email) && $m->type === 'order_delivered');
});

it('respects switched-off preferences but always sends money, dispute and safety e-mails', function (): void {
    $this->actingAs($this->user)->put('/settings/account/emails', ['order' => false, 'merchant' => true, 'ticket' => true, 'deals' => true])->assertSessionHasNoErrors();
    $this->get('/settings/account')->assertInertia(fn (Assert $page) => $page->where('account.emails', ['order' => false, 'merchant' => true, 'ticket' => true, 'deals' => true]));

    Notifier::notify($this->user->id, 'order_completed', 'order', ['number' => 'X2K-2']);
    Notifier::notify($this->user->id, 'dispute_opened', 'dispute', ['number' => 'X2K-2']);
    Notifier::notify($this->user->id, 'withdrawal_paid', 'merchant', []);

    Mail::assertQueued(NotificationMail::class, 2);
    Mail::assertNotQueued(NotificationMail::class, fn (NotificationMail $m): bool => $m->type === 'order_completed');
});

it('does not e-mail accounts that cannot log in', function (): void {
    $this->user->forceFill(['status' => UserStatus::Suspended])->save();
    Notifier::notify($this->user->id, 'dispute_opened', 'dispute', ['number' => 'X2K-3']);

    Mail::assertNothingQueued();
});

it('renders the branded e-mail in the user\'s language with an absolute link', function (): void {
    $this->user->forceFill(['locale' => 'fr'])->save();
    config(['app.url' => 'https://x2key.example']);

    $mail = new NotificationMail($this->user, 'order_delivered', ['number' => 'X2K-9', 'href' => '/buy/orders/abc']);
    $html = $mail->render();

    expect($html)->toContain('Commande X2K-9 livrée')
        ->toContain('https://x2key.example/buy/orders/abc')
        ->toContain('/brand/x2key-logo-email.png')
        ->toContain('#d91a21')
        ->and($mail->envelope()->subject)->toBe('Commande X2K-9 livrée');
});
