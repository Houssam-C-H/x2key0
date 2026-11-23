<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserSession;
use App\Shared\Audit\AuditLog;

function otherSession(User $user): UserSession
{
    return UserSession::query()->create([
        'user_id' => $user->id,
        'session_id' => 'other-browser-session',
        'ip' => '203.0.113.9',
        'user_agent' => 'Mozilla/5.0 (Android) Chrome/120',
        'last_active_at' => now(),
    ]);
}

it('registers the current session on the first authenticated request', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();

    $record = UserSession::query()->where('user_id', $user->id)->sole();
    expect($record->session_id)->toBe(session()->getId())
        ->and($user->fresh()?->last_seen_at)->not->toBeNull();
});

it('logs a revoked session out on its next request', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/dashboard');

    UserSession::query()->where('user_id', $user->id)->update(['revoked_at' => now()]);

    // A real browser sends its session cookie back; the test client must do so explicitly.
    $this->withCookie(config()->string('session.cookie'), session()->getId())
        ->get('/dashboard', ['Accept-Language' => 'en'])
        ->assertRedirect('/en/login');
    $this->assertGuest();
});

it('logs a user out mid-session when staff suspend the account', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/dashboard')->assertOk();

    $user->forceFill(['status' => UserStatus::Suspended])->save();

    $this->get('/dashboard')->assertRedirect();
    $this->assertGuest();
});

it('lets the user sign out one other session', function (): void {
    $user = User::factory()->create();
    $other = otherSession($user);

    $this->actingAs($user)->delete("/settings/security/sessions/{$other->id}")->assertRedirect();

    expect($other->fresh()?->revoked_at)->not->toBeNull();
});

it('cannot revoke another user\'s session', function (): void {
    $victim = User::factory()->create();
    $session = otherSession($victim);

    $this->actingAs(User::factory()->create())
        ->delete("/settings/security/sessions/{$session->id}")
        ->assertNotFound();

    expect($session->fresh()?->revoked_at)->toBeNull();
});

it('signs out all other sessions after confirming the password, keeping this one', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/dashboard');
    $other = otherSession($user);

    $this->post('/settings/security/sessions/revoke-others', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    expect($other->fresh()?->revoked_at)->toBeNull();

    $this->post('/settings/security/sessions/revoke-others', ['current_password' => 'password'])->assertSessionHasNoErrors();

    expect($other->fresh()?->revoked_at)->not->toBeNull()
        ->and(UserSession::query()->where('session_id', session()->getId())->value('revoked_at'))->toBeNull()
        ->and(AuditLog::query()->where('action', 'user_sessions.revoked_others')->exists())->toBeTrue();
});

it('signs out other sessions when the password changes', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/dashboard');
    $other = otherSession($user);

    $this->put('/user/password', [
        'current_password' => 'password',
        'password' => 'brand-new-pass-99',
        'password_confirmation' => 'brand-new-pass-99',
    ])->assertSessionHasNoErrors();

    expect($other->fresh()?->revoked_at)->not->toBeNull()
        ->and(UserSession::query()->where('session_id', session()->getId())->value('revoked_at'))->toBeNull();
});

it('signs out every session after a forgotten-password reset', function (): void {
    $user = User::factory()->create();
    $other = otherSession($user);
    $token = Password::broker()->createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-pass-99',
        'password_confirmation' => 'brand-new-pass-99',
    ])->assertSessionHasNoErrors();

    expect($other->fresh()?->revoked_at)->not->toBeNull();
});
