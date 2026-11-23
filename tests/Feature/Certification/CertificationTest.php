<?php

declare(strict_types=1);

use App\Domains\Certification\Actions\ReviewCertification;
use App\Domains\Certification\Enums\CertificationStatus;
use App\Domains\Certification\Enums\CertificationType;
use App\Domains\Certification\Models\Certification;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Shared\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

function identityPayload(array $overrides = []): array
{
    return [
        'first_name' => 'Amina',
        'last_name' => 'Benali',
        'date_of_birth' => now()->subYears(25)->toDateString(),
        'country' => 'ma',
        'document_type' => 'passport',
        ...$overrides,
    ];
}

it('lists all levels with status and what blocks them', function (): void {
    $user = User::factory()->create(); // email verified, phone not

    $this->actingAs($user)->get('/settings/certification')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Certification')
            ->has('items', 6)
            ->where('items.0.type', 'email')
            ->where('items.0.status', 'verified')
            ->where('items.1.status', 'none')
            ->where('items.2.type', 'identity')
            ->where('items.2.blockedBy', ['phone']));
});

it('requires email and phone before identity', function (): void {
    $this->actingAs(User::factory()->create())
        ->post('/settings/certification/identity', identityPayload())
        ->assertSessionHasErrors('identity');

    expect(Certification::query()->count())->toBe(0);
});

it('submits identity: pending, fake provider, personal data encrypted at rest', function (): void {
    $user = User::factory()->withVerifiedPhone()->create();

    $this->actingAs($user)->post('/settings/certification/identity', identityPayload())->assertSessionHasNoErrors();

    $certification = Certification::query()->sole();
    expect($certification->status)->toBe(CertificationStatus::Pending)
        ->and($certification->type)->toBe(CertificationType::Identity)
        ->and($certification->provider)->toBe('fake')
        ->and($certification->data_encrypted['last_name'] ?? null)->toBe('Benali');

    $raw = (string) DB::table('certifications')->value('data_encrypted');
    expect($raw)->not->toContain('Benali')->not->toContain('Amina');

    $audit = AuditLog::query()->where('action', 'certifications.created')->sole();
    expect(json_encode($audit->after))->not->toContain('Benali');
});

it('blocks a second submission while pending', function (): void {
    $user = User::factory()->withVerifiedPhone()->create();
    $this->actingAs($user)->post('/settings/certification/identity', identityPayload());

    $this->post('/settings/certification/identity', identityPayload())->assertSessionHasErrors('identity');
    expect(Certification::query()->count())->toBe(1);
});

it('rejects minors and bad document types', function (array $overrides, string $field): void {
    $this->actingAs(User::factory()->withVerifiedPhone()->create())
        ->post('/settings/certification/identity', identityPayload($overrides))
        ->assertSessionHasErrors($field);
})->with([
    [['date_of_birth' => now()->subYears(16)->toDateString()], 'date_of_birth'],
    [['document_type' => 'library_card'], 'document_type'],
]);

it('lets support approve; the user becomes identity-verified', function (): void {
    $user = User::factory()->withVerifiedPhone()->create();
    $this->actingAs($user)->post('/settings/certification/identity', identityPayload());
    $certification = Certification::query()->sole();
    $support = User::factory()->staff(StaffRole::Support)->create();

    expect(Gate::forUser($support)->allows('review', $certification))->toBeTrue();

    app(ReviewCertification::class)->approve($certification, $support);

    $user->refresh()->load('certifications');
    expect($user->isCertified(CertificationType::Identity))->toBeTrue()
        ->and($certification->fresh()?->reviewed_by)->toBe($support->id);
});

it('allows resubmission after a rejection and shows the reason', function (): void {
    $user = User::factory()->withVerifiedPhone()->create();
    $this->actingAs($user)->post('/settings/certification/identity', identityPayload());
    app(ReviewCertification::class)->reject(Certification::query()->sole(), null, 'Document photo is blurry');

    $this->get('/settings/certification')->assertInertia(fn (Assert $page) => $page
        ->where('items.2.status', 'rejected')
        ->where('items.2.rejectedReason', 'Document photo is blurry'));

    $this->post('/settings/certification/identity', identityPayload())->assertSessionHasNoErrors();
    expect(Certification::query()->sole()->status)->toBe(CertificationStatus::Pending);
});

it('only reviews pending certifications', function (): void {
    $certification = Certification::query()->create([
        'user_id' => User::factory()->create()->id,
        'type' => CertificationType::Identity,
        'status' => CertificationStatus::Verified,
    ]);

    app(ReviewCertification::class)->approve($certification, null);
})->throws(DomainException::class);

it('nobody reviews their own certification, not even an admin', function (): void {
    $admin = User::factory()->staff(StaffRole::Admin)->create();
    $own = Certification::query()->create(['user_id' => $admin->id, 'type' => CertificationType::Identity, 'status' => CertificationStatus::Pending]);
    $other = Certification::query()->create(['user_id' => User::factory()->create()->id, 'type' => CertificationType::Identity, 'status' => CertificationStatus::Pending]);

    expect(Gate::forUser($admin)->allows('review', $own))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('review', $other))->toBeTrue();
});

it('buyers and moderators cannot review', function (): void {
    $certification = Certification::query()->create(['user_id' => User::factory()->create()->id, 'type' => CertificationType::Identity, 'status' => CertificationStatus::Pending]);

    expect(Gate::forUser(User::factory()->create())->allows('review', $certification))->toBeFalse()
        ->and(Gate::forUser(User::factory()->staff(StaffRole::Moderator)->create())->allows('review', $certification))->toBeFalse();
});
