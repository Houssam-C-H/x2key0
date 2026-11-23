<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserSecurity;
use App\Domains\Identity\Models\UserSession;
use App\Shared\Audit\AuditLog;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Route::middleware(['web', 'auth', 'privacy'])->get('/_test/vault', fn () => 'vault open');
});

function setPrivacy(string $privacy = 'vault-pass-1', string $current = 'password'): TestResponse
{
    return test()->put('/settings/security/privacy-password', [
        'current_password' => $current,
        'privacy_password' => $privacy,
        'privacy_password_confirmation' => $privacy,
    ]);
}

it('sets a privacy password after checking the account password', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    setPrivacy(current: 'wrong')->assertSessionHasErrorsIn('privacyPassword', 'current_password');
    setPrivacy()->assertSessionHasNoErrors();

    $security = UserSecurity::query()->findOrFail($user->id);
    expect($security->privacy_password_hash)->not->toBeNull()
        ->and($security->privacy_password_hash)->not->toContain('vault-pass-1')
        ->and(AuditLog::query()->where('action', 'privacy_password.set')->exists())->toBeTrue();
});

it('must differ from the login password', function (): void {
    $this->actingAs(User::factory()->create());

    setPrivacy(privacy: 'password')->assertSessionHasErrorsIn('privacyPassword', 'privacy_password');
});

it('signs out other sessions when it changes', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/dashboard');
    $other = UserSession::query()->create(['user_id' => $user->id, 'session_id' => 'elsewhere', 'last_active_at' => now()]);

    setPrivacy()->assertSessionHasNoErrors();

    expect($other->fresh()?->revoked_at)->not->toBeNull();
});

it('gates protected areas: set it first, then enter it, then pass for 15 minutes', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/_test/vault')->assertRedirect('/settings/security');

    setPrivacy();

    $this->get('/_test/vault')->assertRedirect('/security/privacy-verify?return=%2F_test%2Fvault');

    $this->post('/security/privacy-verify', ['privacy_password' => 'wrong', 'return' => '/_test/vault'])->assertSessionHasErrors('privacy_password');
    $this->post('/security/privacy-verify', ['privacy_password' => 'vault-pass-1', 'return' => '/_test/vault'])->assertRedirect('/_test/vault');

    $this->get('/_test/vault')->assertOk()->assertSee('vault open');

    $this->travel(16)->minutes();
    $this->get('/_test/vault')->assertRedirect();
});

it('never redirects off-site after verification', function (): void {
    $this->actingAs(User::factory()->create());
    setPrivacy();

    $this->post('/security/privacy-verify', ['privacy_password' => 'vault-pass-1', 'return' => 'https://evil.example'])
        ->assertRedirect('/dashboard');
});
