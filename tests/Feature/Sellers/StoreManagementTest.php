<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Actions\ApplyForService;
use App\Domains\Sellers\Actions\ReviewServiceApplication;
use App\Domains\Sellers\Enums\ContactChannel;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Enums\ServiceApplicationStatus;
use App\Domains\Sellers\Exceptions\SellerException;
use App\Domains\Sellers\Models\MerchantContact;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerServiceApplication;
use App\Domains\Sellers\Models\SellerStatusChange;
use App\Shared\Audit\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CatalogFixture;

beforeEach(function (): void {
    $this->profile = SellerProfile::factory()->create(['store_name' => 'Pixel Vault', 'slug' => 'pixel-vault']);
    $this->seller = $this->profile->user;
});

function storePayload(array $overrides = []): array
{
    return [
        'store_notice' => ['en' => 'Orders ship within 10 minutes.', 'fr' => '', 'ar' => ''],
        'auto_reply' => ['first_message' => 'Hi! I will reply shortly.', 'off_hours_message' => '', 'hours_start' => '09:00', 'hours_end' => '21:00'],
        'languages' => [['code' => 'en', 'level' => 'fluent'], ['code' => 'ar', 'level' => 'native']],
        'vacation' => false,
        'vacation_until' => null,
        ...$overrides,
    ];
}

// ── My Store (store settings) ─────────────────────────────────────────────

it('saves the store notice, auto-reply and languages', function (): void {
    $this->actingAs($this->seller)->put('/seller/store', storePayload())->assertSessionHasNoErrors();

    $profile = $this->profile->refresh();
    expect($profile->store_notice)->toBe(['en' => 'Orders ship within 10 minutes.'])
        ->and($profile->auto_reply)->toBe(['first_message' => 'Hi! I will reply shortly.', 'off_hours_message' => null, 'hours_start' => '09:00', 'hours_end' => '21:00'])
        ->and($profile->media?->languages)->toBe([['code' => 'en', 'level' => 'fluent'], ['code' => 'ar', 'level' => 'native']]);

    $this->get('/seller/store')->assertInertia(fn (Assert $page) => $page
        ->component('Seller/Store')
        ->where('store.notice.en', 'Orders ship within 10 minutes.')
        ->where('store.notice.fr', null)
        ->where('store.autoReply.hours_start', '09:00')
        ->has('store.languages', 2)
        ->where('featuredMax', 4));
});

it('rejects contact details, unknown languages and half-set hours', function (array $overrides, string $field): void {
    $this->actingAs($this->seller)->put('/seller/store', storePayload($overrides))->assertSessionHasErrors($field);
})->with([
    'email in notice' => [['store_notice' => ['en' => 'Write to me at deals@example.com']], 'store_notice.en'],
    'telegram in auto-reply' => [['auto_reply' => ['first_message' => 'telegram: @fastkeys', 'hours_start' => null, 'hours_end' => null]], 'auto_reply.first_message'],
    'phone in notice' => [['store_notice' => ['fr' => 'Appelez le +33 6 12 34 56 78']], 'store_notice.fr'],
    'unknown language' => [['languages' => [['code' => 'xx', 'level' => 'native']]], 'languages.0.code'],
    'duplicate language' => [['languages' => [['code' => 'en', 'level' => 'native'], ['code' => 'en', 'level' => 'basic']]], 'languages.0.code'],
    'bad level' => [['languages' => [['code' => 'en', 'level' => 'guru']]], 'languages.0.level'],
    'hours start only' => [['auto_reply' => ['first_message' => null, 'hours_start' => '09:00', 'hours_end' => null]], 'auto_reply.hours_end'],
    'vacation in the past' => [['vacation' => true, 'vacation_until' => '2020-01-01'], 'vacation_until'],
]);

it('turns vacation mode on and off through the state machine', function (): void {
    $until = now()->addDays(10)->toDateString();

    $this->actingAs($this->seller)->put('/seller/store', storePayload(['vacation' => true, 'vacation_until' => $until]))->assertSessionHasNoErrors();
    $profile = $this->profile->refresh();
    expect($profile->status)->toBe(SellerStatus::Vacation)
        ->and($profile->vacation_until?->toDateString())->toBe($until);

    // Still opens the Seller Center and shows publicly with a notice.
    $this->get('/seller/store')->assertOk();
    $this->get('/en/store/pixel-vault')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('store.onVacation', true)
        ->where('store.vacationUntil', $until));

    $this->put('/seller/store', storePayload())->assertSessionHasNoErrors();
    expect($this->profile->refresh()->status)->toBe(SellerStatus::Active)
        ->and($this->profile->vacation_until)->toBeNull()
        ->and(SellerStatusChange::query()->pluck('to_status')->map->value->all())->toBe(['vacation', 'active']);
});

it('keeps suspended sellers read-only', function (): void {
    $this->profile->forceFill(['status' => SellerStatus::Suspended, 'status_reason' => 'Chargeback review'])->save();

    $this->actingAs($this->seller)->get('/seller/store')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('store.status', 'suspended')
        ->where('store.statusReason', 'Chargeback review'));

    $this->put('/seller/store', storePayload())->assertSessionHasErrors('store');
    $this->post('/seller/info', ['store_name' => 'New Name'])->assertSessionHasErrors('store_name');
    $this->post('/seller/services', ['game_category_id' => 1])->assertSessionHasErrors('game_category_id');
    $this->get('/en/store/pixel-vault')->assertNotFound();
});

// ── Seller Information ────────────────────────────────────────────────────

it('updates the shop name, description, avatar and banner', function (): void {
    Storage::fake('public');

    $this->actingAs($this->seller)->post('/seller/info', [
        'store_name' => 'Key Forge',
        'bio' => ['en' => 'Instant keys for PC.', 'fr' => 'Clés instantanées.', 'ar' => ''],
        'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        'banner' => UploadedFile::fake()->image('banner.jpg', 1920, 200),
    ])->assertSessionHasNoErrors();

    $profile = $this->profile->refresh();
    expect($profile->store_name)->toBe('Key Forge')
        ->and($profile->slug)->toBe('pixel-vault') // URL stays stable
        ->and($profile->bio)->toBe(['en' => 'Instant keys for PC.', 'fr' => 'Clés instantanées.']);
    Storage::disk('public')->assertExists([(string) $profile->logo_path, (string) $profile->banner_path]);

    // Replacing the logo deletes the old file.
    $old = (string) $profile->logo_path;
    $this->post('/seller/info', ['store_name' => 'Key Forge', 'logo' => UploadedFile::fake()->image('new.webp', 300, 300)])->assertSessionHasNoErrors();
    Storage::disk('public')->assertMissing($old);

    expect(AuditLog::query()->where('action', 'seller_profiles.updated')->count())->toBe(2);

    $this->get('/seller/info')->assertInertia(fn (Assert $page) => $page
        ->component('Seller/Info')
        ->where('store.name', 'Key Forge')
        ->where('store.bio.fr', 'Clés instantanées.')
        ->whereNot('store.logoUrl', null));
});

it('validates the seller information form', function (array $payload, string $field): void {
    Storage::fake('public');
    SellerProfile::factory()->create(['store_name' => 'Taken Store']);

    $this->actingAs($this->seller)->post('/seller/info', ['store_name' => 'Pixel Vault', ...$payload])->assertSessionHasErrors($field);
})->with([
    'name taken (any case)' => [['store_name' => 'taken store'], 'store_name'],
    'name too short' => [['store_name' => 'ab'], 'store_name'],
    'name with a link' => [['store_name' => 'buy at keys.com'], 'store_name'],
    'bio with email' => [['bio' => ['en' => 'mail me: a.b@gmail.com']], 'bio.en'],
    'bio with whatsapp link' => [['bio' => ['ar' => 'wa.me/212600000000']], 'bio.ar'],
    'logo too small' => [['logo' => UploadedFile::fake()->image('l.png', 50, 50)], 'logo'],
    'logo not an image' => [['logo' => UploadedFile::fake()->create('l.pdf', 10, 'application/pdf')], 'logo'],
    'banner too narrow' => [['banner' => UploadedFile::fake()->image('b.png', 600, 200)], 'banner'],
]);

// ── Service applications ──────────────────────────────────────────────────

it('applies for a service, blocks duplicates and lets refused applications reapply', function (): void {
    $gameCategory = CatalogFixture::build()['gameCategory'];
    $manager = User::factory()->staff(StaffRole::MerchantManager)->create();

    $this->actingAs($this->seller)->post('/seller/services', ['game_category_id' => $gameCategory->id])->assertSessionHasNoErrors();
    $this->post('/seller/services', ['game_category_id' => $gameCategory->id])->assertSessionHasErrors('game_category_id');

    $application = SellerServiceApplication::query()->sole();
    app(ReviewServiceApplication::class)->refuse($application, $manager, 'Add proof of stock first.');

    $this->get('/seller/services')->assertInertia(fn (Assert $page) => $page
        ->component('Seller/Services')
        ->where('applications.0.status', 'refused')
        ->where('applications.0.rejectReason', 'Add proof of stock first.')
        ->where('games.0.categories.0.applied', false));

    $this->post('/seller/services', ['game_category_id' => $gameCategory->id])->assertSessionHasNoErrors();
    $application->refresh();
    expect($application->status)->toBe(ServiceApplicationStatus::Pending)
        ->and($application->reject_reason)->toBeNull()
        ->and(SellerServiceApplication::query()->count())->toBe(1);

    app(ReviewServiceApplication::class)->pass($application, $manager);
    $this->post('/seller/services', ['game_category_id' => $gameCategory->id])->assertSessionHasErrors('game_category_id');

    $this->get('/seller/info')->assertInertia(fn (Assert $page) => $page->has('services', 1));
});

it('passes applications at once for auto-approve levels and refuses hidden game categories', function (): void {
    $gameCategory = CatalogFixture::build()['gameCategory'];
    $gold = SellerProfile::factory()->level('gold')->create();

    $application = app(ApplyForService::class)->handle($gold, $gameCategory->id);
    expect($application->status)->toBe(ServiceApplicationStatus::Passed)
        ->and($application->audited_by)->toBeNull();

    GameCategory::query()->whereKey($gameCategory->id)->update(['is_enabled' => false]);
    $other = SellerProfile::factory()->create();
    expect(fn () => app(ApplyForService::class)->handle($other, $gameCategory->id))->toThrow(SellerException::class);
    expect(fn () => app(ApplyForService::class)->handle($other, 999999))->toThrow(SellerException::class);
});

it('requires a reason to refuse and forbids reviewing your own store', function (): void {
    $gameCategory = CatalogFixture::build()['gameCategory'];
    $application = app(ApplyForService::class)->handle($this->profile, $gameCategory->id);

    expect(fn () => app(ReviewServiceApplication::class)->refuse($application, User::factory()->create(), '  '))->toThrow(SellerException::class)
        ->and(fn () => app(ReviewServiceApplication::class)->pass($application, $this->seller))->toThrow(SellerException::class);
});

// ── Merchant Services Contacts ────────────────────────────────────────────

it('shows the assigned merchant manager, channels and the merchant e-mail', function (): void {
    $manager = User::factory()->staff(StaffRole::MerchantManager)->create(['name' => 'Sara']);
    $this->profile->forceFill(['merchant_manager_id' => $manager->id])->save();
    MerchantContact::query()->create(['seller_profile_id' => $this->profile->id, 'staff_user_id' => $manager->id, 'channel' => ContactChannel::Telegram, 'value' => '@x2key_sara', 'hours' => '09–18 UTC', 'is_primary' => false]);
    MerchantContact::query()->create(['seller_profile_id' => $this->profile->id, 'staff_user_id' => null, 'channel' => ContactChannel::Email, 'value' => 'sara@x2key.test', 'hours' => null, 'is_primary' => true]);

    $this->actingAs($this->seller)->get('/seller/contacts')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Seller/Contacts')
        ->where('manager.name', 'Sara')
        ->has('contacts', 2)
        ->where('contacts.0.channel', 'email')
        ->where('contacts.1.staff', 'Sara')
        ->where('merchantEmail', config('x2key.support.merchant_email')));
});
