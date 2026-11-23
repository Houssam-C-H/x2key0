<?php

declare(strict_types=1);

use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Storage\Contracts\SecretVault;
use App\Domains\Storage\Enums\StorageItemStatus;
use App\Domains\Storage\Enums\StorageItemType;
use App\Domains\Storage\Models\StorageGroup;
use App\Domains\Storage\Models\StorageImportBatch;
use App\Domains\Storage\Models\StorageItem;
use App\Shared\Audit\AuditLog;
use App\Shared\Logging\ScrubSecrets;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->profile = SellerProfile::factory()->create();
    $this->seller = $this->profile->user;
    $this->actingAs($this->seller);
    unlockPrivacy($this->seller);

    $this->group = StorageGroup::query()->create(['seller_profile_id' => $this->profile->id, 'name' => 'Steam keys', 'item_type' => StorageItemType::Key, 'low_stock_threshold' => 2]);
});

function importKeys(int $groupId, string $items): TestResponse
{
    return test()->post("/seller/storage/groups/{$groupId}/import", ['mode' => 'paste', 'items' => $items]);
}

it('puts every Storage page behind the Privacy Password', function (): void {
    $this->flushSession();

    foreach (['/seller/storage', '/seller/storage/items', '/seller/storage/recycle'] as $path) {
        $this->get($path)->assertRedirect('/security/privacy-verify?return='.urlencode($path));
    }
    importKeys($this->group->id, 'AAAA-1111')->assertRedirect();
    expect(StorageItem::query()->count())->toBe(0);
});

it('creates and edits groups; names are unique per seller and the type locks once filled', function (): void {
    $this->post('/seller/storage/groups', ['name' => 'Accounts EU', 'type' => 'account', 'threshold' => 3])->assertSessionHasNoErrors();
    $this->post('/seller/storage/groups', ['name' => 'steam KEYS', 'type' => 'key', 'threshold' => 3])->assertSessionHasErrors('name');

    importKeys($this->group->id, 'AAAA-1111');
    $this->put("/seller/storage/groups/{$this->group->id}", ['name' => 'Steam keys', 'type' => 'code', 'threshold' => 4])->assertSessionHasErrors('name');
    $this->put("/seller/storage/groups/{$this->group->id}", ['name' => 'Steam keys (EU)', 'type' => 'key', 'threshold' => 4])->assertSessionHasNoErrors();

    expect($this->group->refresh()->name)->toBe('Steam keys (EU)')->and($this->group->low_stock_threshold)->toBe(4);

    $other = StorageGroup::query()->create(['seller_profile_id' => SellerProfile::factory()->create()->id, 'name' => 'x', 'item_type' => StorageItemType::Key, 'low_stock_threshold' => 1]);
    $this->put("/seller/storage/groups/{$other->id}", ['name' => 'mine now', 'type' => 'key', 'threshold' => 1])->assertNotFound();
    importKeys($other->id, 'BBBB')->assertNotFound();
});

it('imports with a duplicate report: in-batch, already in the vault, invalid lines', function (): void {
    importKeys($this->group->id, "AAAA-1111\nBBBB-2222")->assertSessionHasNoErrors();

    importKeys($this->group->id, "aaaa 1111\nCCCC-3333\nCCCC3333\n".str_repeat('Z', 1200)."\nDDDD-4444")
        ->assertSessionHas('success', __('storage.import.result', ['accepted' => 2, 'duplicates' => 2, 'invalid' => 1, 'flagged' => 0]));

    $batch = StorageImportBatch::query()->latest('id')->firstOrFail();
    expect([$batch->count_total, $batch->count_accepted, $batch->count_duplicates, $batch->count_invalid])->toBe([5, 2, 2, 1])
        ->and($this->group->refresh()->available_count_cached)->toBe(4);

    $this->get('/seller/storage')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Storage/Groups')
        ->where('groups.0.available', 4)
        ->where('batches.0.duplicates', 2));
});

it('flags cross-seller duplicates and freezes both sides', function (): void {
    $rival = SellerProfile::factory()->create();
    $rivalGroup = StorageGroup::query()->create(['seller_profile_id' => $rival->id, 'name' => 'Keys', 'item_type' => StorageItemType::Key, 'low_stock_threshold' => 0]);
    $this->actingAs($rival->user);
    unlockPrivacy($rival->user);
    importKeys($rivalGroup->id, "SHARED-KEY-1\nRIVAL-ONLY");
    expect($rivalGroup->refresh()->available_count_cached)->toBe(2);

    $this->actingAs($this->seller);
    unlockPrivacy($this->seller);
    importKeys($this->group->id, "shared key 1\nMINE-ONLY")->assertSessionHas('success', __('storage.import.result', ['accepted' => 2, 'duplicates' => 0, 'invalid' => 0, 'flagged' => 1]));

    expect(StorageItem::query()->whereNotNull('flagged_at')->count())->toBe(2)
        ->and($this->group->refresh()->available_count_cached)->toBe(1)
        ->and($rivalGroup->refresh()->available_count_cached)->toBe(1)
        ->and(AuditLog::query()->where('action', 'storage.cross_seller_duplicate')->exists())->toBeTrue();
});

it('encrypts at rest with one data key per item; nothing readable in the database', function (): void {
    importKeys($this->group->id, "PLAIN-SECRET-0001\nPLAIN-SECRET-0002");

    $rows = DB::table('storage_items')->get();
    $dump = json_encode($rows->all(), JSON_THROW_ON_ERROR);

    expect($dump)->not->toContain('PLAIN-SECRET')
        ->and($rows[0]->payload_dek_wrapped)->not->toBe($rows[1]->payload_dek_wrapped)
        ->and(app(SecretVault::class)->open(StorageItem::query()->findOrFail($rows[0]->id)->sealed()))->toBe('PLAIN-SECRET-0001')
        // Sealed columns never reach serialised models (props, JSON, audit rows).
        ->and(StorageItem::query()->firstOrFail()->toArray())->not->toHaveKeys(['payload_ciphertext', 'payload_dek_wrapped', 'payload_hmac']);
});

it('never writes secrets to logs, audit rows or the session — even when the import fails validation', function (): void {
    $logFile = storage_path('logs/m5-secret-test.log');
    File::delete($logFile);
    config(['logging.channels.m5test' => ['driver' => 'single', 'path' => $logFile, 'tap' => [ScrubSecrets::class]]]);
    Log::setDefaultDriver('m5test');

    $secret = 'TOPSECRET-KEY-9999';
    $account = ['login' => 'hero_login', 'password' => 'Sup3rS3cret!pw', 'email' => 'hero@example.com', 'email_password' => 'mailpass-777'];
    $accounts = StorageGroup::query()->create(['seller_profile_id' => $this->profile->id, 'name' => 'Accounts', 'item_type' => StorageItemType::Account, 'low_stock_threshold' => 1]);

    importKeys($this->group->id, $secret);
    $this->post("/seller/storage/groups/{$accounts->id}/import", ['mode' => 'form', 'account' => $account])->assertSessionHasNoErrors();
    // Failing validation must not flash the pasted secrets back into the session.
    $this->post("/seller/storage/groups/{$this->group->id}/import", ['mode' => 'paste', 'items' => $secret.' '.str_repeat('x', 2_000_001)])->assertSessionHasErrors('items');
    expect(session()->getOldInput('items'))->toBeNull();

    // Reveal both: audited, but the audit row carries no secret.
    foreach (StorageItem::query()->get() as $item) {
        $this->postJson("/seller/storage/items/{$item->id}/reveal")->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    // Something logs a context with secret-bearing keys: the scrubber redacts it.
    Log::warning('debug import', ['items' => $secret, 'account' => $account, 'payload_ciphertext' => 'abc']);

    $haystack = json_encode([AuditLog::query()->get()->toArray(), session()->all()], JSON_THROW_ON_ERROR).File::get($logFile);

    foreach ([$secret, 'Sup3rS3cret!pw', 'mailpass-777'] as $needle) {
        expect($haystack)->not->toContain($needle);
    }
    expect(AuditLog::query()->where('action', 'storage_items.revealed')->count())->toBe(2)
        ->and(File::get($logFile))->toContain('[redacted]');

    File::delete($logFile);
});

it('reveals keys and structured accounts to their seller only, never once assigned', function (): void {
    importKeys($this->group->id, 'REVEAL-ME-1');
    $item = StorageItem::query()->sole();

    $this->postJson("/seller/storage/items/{$item->id}/reveal")->assertOk()->assertExactJson(['type' => 'key', 'value' => 'REVEAL-ME-1', 'account' => null]);

    $item->forceFill(['status' => StorageItemStatus::Assigned])->save();
    $this->postJson("/seller/storage/items/{$item->id}/reveal")->assertStatus(422);

    $stranger = SellerProfile::factory()->create()->user;
    $this->actingAs($stranger);
    unlockPrivacy($stranger);
    $this->postJson("/seller/storage/items/{$item->id}/reveal")->assertNotFound();
});

it('lists items without secrets, marks invalid, recycles, restores and purges', function (): void {
    importKeys($this->group->id, "K-1\nK-2\nK-3");
    [$a, $b, $c] = StorageItem::query()->orderBy('id')->pluck('id')->all();

    $this->get('/seller/storage/items')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Storage/Items')
        ->where('items.total', 3)
        ->missing('items.items.0.payload_ciphertext'));

    $this->post('/seller/storage/items/manage', ['operation' => 'invalid', 'ids' => [$a]])->assertSessionHas('success');
    $this->post('/seller/storage/items/manage', ['operation' => 'recycle', 'ids' => [$a, $b]]);
    expect($this->group->refresh()->available_count_cached)->toBe(1);

    $this->get('/seller/storage/recycle')->assertInertia(fn (Assert $page) => $page->component('Storage/Recycle')->where('items.total', 2));

    $this->post('/seller/storage/items/manage', ['operation' => 'restore', 'ids' => [$b]]);
    $this->post('/seller/storage/items/manage', ['operation' => 'purge', 'ids' => [$a, $c]]); // $c is not recycled: untouched

    expect(StorageItem::query()->pluck('id')->sort()->values()->all())->toBe([$b, $c])
        ->and($this->group->refresh()->available_count_cached)->toBe(2)
        ->and(AuditLog::query()->where('action', 'storage_items.purge')->sole()->after)->toBe(['item_ids' => [$a]]);
});

it('imports accounts from a TXT file', function (): void {
    $accounts = StorageGroup::query()->create(['seller_profile_id' => $this->profile->id, 'name' => 'Accounts', 'item_type' => StorageItemType::Account, 'low_stock_threshold' => 1]);
    $file = UploadedFile::fake()->createWithContent('accounts.txt', "u1|p1|u1@example.com\nu2\tp2\nbroken-line\n");

    $this->post("/seller/storage/groups/{$accounts->id}/import", ['mode' => 'file', 'file' => $file])
        ->assertSessionHas('success', __('storage.import.result', ['accepted' => 2, 'duplicates' => 0, 'invalid' => 1, 'flagged' => 0]));

    $this->postJson('/seller/storage/items/'.StorageItem::query()->where('storage_group_id', $accounts->id)->orderBy('id')->value('id').'/reveal')
        ->assertJsonPath('account.login', 'u1')
        ->assertJsonPath('account.email', 'u1@example.com');
});
