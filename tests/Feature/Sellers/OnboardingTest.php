<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Certification\Actions\ReviewCertification;
use App\Domains\Certification\Enums\CertificationStatus;
use App\Domains\Certification\Enums\CertificationType;
use App\Domains\Certification\Models\Certification;
use App\Domains\Identity\Contracts\SmsSender;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Actions\ActivateSeller;
use App\Domains\Sellers\Actions\ReviewServiceApplication;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Enums\ServiceApplicationStatus;
use App\Domains\Sellers\Exceptions\SellerException;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerServiceApplication;
use App\Domains\Sellers\Models\SellerStatusChange;
use Database\Seeders\SellerLevelSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CatalogFixture;

beforeEach(function (): void {
    $this->seed([UserLevelSeeder::class, SellerLevelSeeder::class]);
    Notification::fake();

    $this->sms = new class implements SmsSender
    {
        public string $last = '';

        public function send(string $e164Phone, string $message): void
        {
            preg_match('/\b(\d{6})\b/', $message, $m);
            $this->last = $m[1] ?? '';
        }
    };
    app()->instance(SmsSender::class, $this->sms);
});

/** A user with email + phone verified and the identity certification in the given status. */
function certifiedUser(CertificationStatus $identity = CertificationStatus::Verified): User
{
    $user = User::factory()->withVerifiedPhone()->create();
    Certification::query()->create([
        'user_id' => $user->id,
        'type' => CertificationType::Identity,
        'status' => $identity,
        'provider' => 'fake',
        'verified_at' => $identity === CertificationStatus::Verified ? now() : null,
    ]);

    return $user->refresh();
}

it('onboards a seller end to end: sign-up → email → phone → identity → activate → service → public store', function (): void {
    $fx = CatalogFixture::build();
    /** @var GameCategory $gameCategory */
    $gameCategory = $fx['gameCategory'];

    // 1. Sign up.
    $this->post('/register', [
        'username' => 'pixel_trader',
        'email' => 'pixel@example.com',
        'password' => 'correct-horse-42',
        'password_confirmation' => 'correct-horse-42',
        'terms' => true,
    ])->assertRedirect('/dashboard');

    $user = User::query()->where('username', 'pixel_trader')->sole();

    // Not a seller yet: seller pages send to onboarding, which shows the checklist locked.
    $this->get('/seller/store')->assertRedirect('/seller/onboarding');
    $this->get('/seller/onboarding')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Seller/Onboarding')
        ->where('canActivate', false)
        ->where('steps.0.status', 'none')
        ->has('levels', 6));
    $this->post('/seller/activate')->assertSessionHasErrors('activate');

    // 2. Verify the e-mail address through the signed link.
    $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]));
    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();

    // 3. Verify the phone by SMS code.
    $this->post('/settings/security/phone/code', ['phone' => '+212612345678'])->assertSessionHasNoErrors();
    $this->post('/settings/security/phone/confirm', ['code' => $this->sms->last])->assertSessionHasNoErrors();

    // 4. Submit identity; staff approve it.
    $this->post('/settings/certification/identity', [
        'first_name' => 'Amina', 'last_name' => 'Benali', 'date_of_birth' => now()->subYears(30)->toDateString(),
        'country' => 'MA', 'document_type' => 'passport',
    ])->assertSessionHasNoErrors();

    $this->get('/seller/onboarding')->assertInertia(fn (Assert $page) => $page
        ->where('canActivate', false)
        ->where('steps.2.status', 'pending'));

    $reviewer = User::factory()->staff(StaffRole::Support)->create();
    app(ReviewCertification::class)->approve(Certification::query()->where('user_id', $user->id)->sole(), $reviewer);

    // 5. Activate.
    $this->get('/seller/onboarding')->assertInertia(fn (Assert $page) => $page->where('canActivate', true));
    $this->post('/seller/activate')->assertRedirect('/seller/store');

    $profile = SellerProfile::query()->where('user_id', $user->id)->sole();
    expect($profile->status)->toBe(SellerStatus::Active)
        ->and($profile->slug)->toBe('pixel-trader')
        ->and($profile->store_name)->toBe('pixel_trader')
        ->and($profile->level?->code)->toBe('new')
        ->and($profile->activated_at)->not->toBeNull()
        ->and($profile->stats)->not->toBeNull()
        ->and($user->refresh()->isCertified(CertificationType::Store))->toBeTrue();

    $history = SellerStatusChange::query()->where('seller_profile_id', $profile->id)->sole();
    expect($history->from_status)->toBe(SellerStatus::Inactive)
        ->and($history->to_status)->toBe(SellerStatus::Active)
        ->and($history->actor_id)->toBe($user->id);

    // The Seller Center opens; onboarding now forwards to it.
    $this->get('/seller/store')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Seller/Store')
        ->where('store.status', 'active')
        ->where('auth.user.seller.slug', 'pixel-trader')
        ->has('menu.groups', 10));
    $this->get('/seller/onboarding')->assertRedirect('/seller/store');

    // 6. Apply for a service; staff pass it.
    $this->post('/seller/services', ['game_category_id' => $gameCategory->id])->assertSessionHasNoErrors();
    $application = SellerServiceApplication::query()->sole();
    expect($application->status)->toBe(ServiceApplicationStatus::Pending)
        ->and($profile->canSellIn($gameCategory->id))->toBeFalse();

    $manager = User::factory()->staff(StaffRole::MerchantManager)->create();
    app(ReviewServiceApplication::class)->pass($application, $manager);
    expect($profile->canSellIn($gameCategory->id))->toBeTrue();

    // 7. The public store lists the service.
    $this->get('/en/store/pixel-trader')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Public/Store')
        ->where('store.name', 'pixel_trader')
        ->where('store.level.code', 'new')
        ->where('isOwner', true)
        ->has('services', 1)
        ->where('services.0.id', $gameCategory->id));
});

it('refuses activation until email, phone and identity are verified', function (CertificationStatus $identity): void {
    $user = certifiedUser($identity);

    expect(fn () => app(ActivateSeller::class)->handle($user))->toThrow(SellerException::class);
    expect(SellerProfile::query()->where('status', SellerStatus::Active)->exists())->toBeFalse();
})->with([CertificationStatus::Pending, CertificationStatus::Rejected]);

it('refuses activation without a verified phone', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/seller/activate')->assertSessionHasErrors('activate');
    expect(SellerProfile::query()->exists())->toBeFalse();
});

it('is idempotent for active sellers and refuses banned stores and suspended accounts', function (): void {
    $user = certifiedUser();
    $profile = app(ActivateSeller::class)->handle($user);

    expect(app(ActivateSeller::class)->handle($user->refresh())->id)->toBe($profile->id)
        ->and(SellerStatusChange::query()->count())->toBe(1);

    $profile->forceFill(['status' => SellerStatus::Banned])->save();
    expect(fn () => app(ActivateSeller::class)->handle($user->refresh()))->toThrow(SellerException::class, __('seller.error.banned'));

    $other = certifiedUser();
    $other->forceFill(['status' => UserStatus::Suspended])->save();
    expect(fn () => app(ActivateSeller::class)->handle($other))->toThrow(SellerException::class);
});

it('gives each store a unique slug and name', function (): void {
    $a = User::factory()->withVerifiedPhone()->create(['username' => 'Neo_Shop']);
    $b = certifiedUser();
    Certification::query()->create(['user_id' => $a->id, 'type' => CertificationType::Identity, 'status' => CertificationStatus::Verified, 'provider' => 'fake', 'verified_at' => now()]);

    SellerProfile::factory()->create(['slug' => 'neo-shop', 'store_name' => 'Neo_Shop', 'user_id' => $b->id]);

    $profile = app(ActivateSeller::class)->handle($a->refresh());

    expect($profile->slug)->toBe('neo-shop-2')
        ->and($profile->store_name)->toBe('Neo_Shop-2');
});

it('shows the store certification as available and verified after activation', function (): void {
    $user = certifiedUser();

    $this->actingAs($user)->get('/settings/certification')->assertInertia(fn (Assert $page) => $page
        ->where('items.4.type', 'store')
        ->where('items.4.available', true)
        ->where('items.4.status', 'none'));

    app(ActivateSeller::class)->handle($user);

    $this->actingAs($user->fresh())->get('/settings/certification')->assertInertia(fn (Assert $page) => $page->where('items.4.status', 'verified'));
});
