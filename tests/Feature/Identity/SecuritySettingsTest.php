<?php

declare(strict_types=1);

use App\Domains\Identity\Contracts\SmsSender;
use App\Domains\Identity\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;

final class FakeSms implements SmsSender
{
    /** @var list<array{to: string, message: string}> */
    public array $sent = [];

    public function send(string $e164Phone, string $message): void
    {
        $this->sent[] = ['to' => $e164Phone, 'message' => $message];
    }

    public function lastCode(): string
    {
        preg_match('/\b(\d{6})\b/', end($this->sent)['message'] ?? '', $m);

        return $m[1] ?? '';
    }
}

beforeEach(function (): void {
    $this->sms = new FakeSms;
    app()->instance(SmsSender::class, $this->sms);
});

it('shows the security page with sessions and history', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/security')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Security')
            ->where('email.address', $user->email)
            ->where('twoFactor.enabled', false)
            ->where('privacyPassword.set', false)
            ->has('sessions', 1));
});

it('changes the email with the current password and asks to verify it', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put('/settings/security/email', ['email' => 'New@Example.com', 'current_password' => 'password'])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->email)->toBe('new@example.com')->and($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('refuses an email change with the wrong password or a taken address', function (): void {
    $user = User::factory()->create();
    User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($user)->put('/settings/security/email', ['email' => 'free@example.com', 'current_password' => 'nope'])
        ->assertSessionHasErrorsIn('changeEmail', 'current_password');
    $this->put('/settings/security/email', ['email' => 'taken@example.com', 'current_password' => 'password'])
        ->assertSessionHasErrorsIn('changeEmail', 'email');

    expect($user->fresh()?->email)->not->toBe('free@example.com');
});

it('verifies a phone number by SMS code', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/security/phone/code', ['phone' => '+212612345678'])->assertSessionHasNoErrors();

    expect($this->sms->sent)->toHaveCount(1)
        ->and($this->sms->sent[0]['to'])->toBe('+212612345678');

    $this->post('/settings/security/phone/confirm', ['code' => $this->sms->lastCode()])->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->phone)->toBe('+212612345678')->and($user->hasVerifiedPhone())->toBeTrue();
});

it('rejects wrong codes and locks after 5 attempts', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/settings/security/phone/code', ['phone' => '+212612345678']);
    $real = $this->sms->lastCode();
    $wrong = $real === '111111' ? '222222' : '111111';

    foreach (range(1, 5) as $i) {
        $this->post('/settings/security/phone/confirm', ['code' => $wrong])->assertSessionHasErrorsIn('phone', 'code');
    }

    // Even the right code no longer works once locked.
    $this->post('/settings/security/phone/confirm', ['code' => $real])->assertSessionHasErrorsIn('phone', 'code');
    expect($user->fresh()?->hasVerifiedPhone())->toBeFalse();
});

it('allows one code per minute', function (): void {
    $this->actingAs(User::factory()->create());

    $this->post('/settings/security/phone/code', ['phone' => '+212612345678'])->assertSessionHasNoErrors();
    $this->post('/settings/security/phone/code', ['phone' => '+212612345678'])->assertSessionHasErrorsIn('phone', 'phone');

    expect($this->sms->sent)->toHaveCount(1);
});

it('validates the phone format', function (string $phone): void {
    $this->actingAs(User::factory()->create())
        ->post('/settings/security/phone/code', ['phone' => $phone])
        ->assertSessionHasErrorsIn('phone', 'phone');
})->with(['0612345678', '+0123456789', '+1 234 567', 'abc']);

it('needs a recent password confirmation before enabling 2FA', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/user/two-factor-authentication')->assertRedirect('/user/confirm-password');
    expect($user->fresh()?->two_factor_secret)->toBeNull();

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->post('/user/two-factor-authentication')
        ->assertSessionHasNoErrors();

    $this->get('/settings/security')->assertInertia(fn (Assert $page) => $page
        ->where('twoFactor.pendingConfirmation', true)
        ->where('twoFactor.qrSvg', fn ($svg) => is_string($svg) && str_contains($svg, '<svg')));
});

it('confirms the password through the real endpoint and rejects a wrong one', function (): void {
    $this->actingAs(User::factory()->create());

    $this->post('/user/confirm-password', ['password' => 'wrong'])->assertSessionHasErrors('password');
    expect(session('auth.password_confirmed_at'))->toBeNull();

    $this->get('/user/confirm-password?return=/settings/security%23two-factor');
    $this->post('/user/confirm-password', ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/security#two-factor');
    expect(session('auth.password_confirmed_at'))->toBeInt();
});

it('turns on 2FA end to end: confirm password, enable, scan, confirm code, see recovery codes', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post('/user/confirm-password', ['password' => 'password'])->assertSessionHasNoErrors();
    $this->post('/user/two-factor-authentication')->assertSessionHasNoErrors();

    $secret = decrypt((string) $user->fresh()?->two_factor_secret);
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->post('/user/confirmed-two-factor-authentication', ['code' => '000000'])
        ->assertSessionHasErrorsIn('confirmTwoFactorAuthentication', 'code');

    $this->post('/user/confirmed-two-factor-authentication', ['code' => $code])->assertSessionHasNoErrors();

    $this->get('/settings/security')->assertInertia(fn (Assert $page) => $page
        ->where('twoFactor.enabled', true)
        ->where('twoFactor.pendingConfirmation', false)
        ->where('twoFactor.recoveryCodes', fn ($codes) => count($codes) === 8));

    expect($user->fresh()?->hasTwoFactorEnabled())->toBeTrue();
});

it('sends the confirm-password page back to the page that asked', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/user/confirm-password?return=/settings/security%23two-factor')
        ->assertOk();

    expect(session('url.intended'))->toBe('/settings/security#two-factor');

    $this->get('/user/confirm-password?return=https://evil.example');
    expect(session('url.intended'))->toBe('/settings/security');
});
