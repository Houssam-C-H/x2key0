<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\CategoryType;
use App\Domains\Catalog\Models\Game;
use App\Domains\Catalog\Models\Product;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Shared\Audit\AuditLog;
use Illuminate\Support\Facades\Gate;
use Tests\Support\CatalogFixture;

beforeEach(function (): void {
    $this->fx = CatalogFixture::build();
});

it('lets moderators manage the catalog; support, finance and buyers cannot', function (): void {
    $moderator = User::factory()->staff(StaffRole::Moderator)->create();
    $support = User::factory()->staff(StaffRole::Support)->create();
    $finance = User::factory()->staff(StaffRole::Finance)->create();
    $buyer = User::factory()->create();
    $game = $this->fx['game'];

    foreach ([$moderator] as $allowed) {
        expect(Gate::forUser($allowed)->allows('update', $game))->toBeTrue()
            ->and(Gate::forUser($allowed)->allows('create', Product::class))->toBeTrue();
    }

    foreach ([$support, $finance, $buyer] as $denied) {
        expect(Gate::forUser($denied)->allows('update', $game))->toBeFalse()
            ->and(Gate::forUser($denied)->allows('viewAny', Game::class))->toBeFalse();
    }
});

it('opens every catalog admin screen for a moderator with 2FA', function (string $path): void {
    $this->actingAs(User::factory()->staff(StaffRole::Moderator)->withTwoFactor()->create())
        ->get($path)
        ->assertOk();
})->with([
    '/admin/catalog/category-types',
    '/admin/catalog/games',
    '/admin/catalog/games/create',
    '/admin/catalog/game-categories',
    '/admin/catalog/game-categories/create',
    '/admin/catalog/products',
    '/admin/catalog/products/create',
    '/admin/catalog/platforms',
    '/admin/catalog/regions',
]);

it('opens the edit screens with the schema builder', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::Moderator)->withTwoFactor()->create());

    $this->get("/admin/catalog/games/{$this->fx['game']->id}/edit")->assertOk();
    $this->get("/admin/catalog/game-categories/{$this->fx['gameCategory']->id}/edit")->assertOk()->assertSee('edition');
    $this->get('/admin/catalog/products/'.$this->fx['products']['p1']->id.'/edit')->assertOk();
    $this->get('/admin/catalog/category-types/'.CategoryType::query()->firstOrFail()->id.'/edit')->assertOk();
});

it('forbids catalog admin for support staff', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::Support)->withTwoFactor()->create())
        ->get('/admin/catalog/games')
        ->assertForbidden();
});

it('audits catalog changes', function (): void {
    $this->fx['game']->update(['is_hot' => true]);

    expect(AuditLog::query()->where('action', 'games.updated')->where('subject_id', $this->fx['game']->id)->exists())->toBeTrue();
});
