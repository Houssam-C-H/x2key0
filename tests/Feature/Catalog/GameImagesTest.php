<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Game;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Filament\Resources\Catalog\Games\Pages\EditGame;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Support\CatalogFixture;

/*
 * Game pictures: icon / cover / banner uploaded in the admin, shown on the site; without one the site falls back
 * to generated art (null URLs).
 */

it('builds same-site URLs for local uploads, keeps absolute ones, and falls back icon ⇄ cover', function (): void {
    $game = Game::factory()->create(['icon_path' => null, 'cover_path' => 'games/covers/a.webp', 'hero_path' => null]);

    expect($game->iconUrl())->toBe('/storage/games/covers/a.webp')
        ->and($game->coverUrl())->toBe('/storage/games/covers/a.webp')
        ->and($game->heroUrl())->toBeNull()
        ->and(Game::publicUrl('https://cdn.example/x.png'))->toBe('https://cdn.example/x.png')
        ->and(Game::publicUrl(''))->toBeNull();
});

it('sends the icon and banner to the game page', function (): void {
    $fx = CatalogFixture::build();
    $fx['game']->forceFill(['icon_path' => 'games/icons/alpha.png', 'hero_path' => 'games/banners/alpha.jpg'])->save();

    $this->get('/en/'.$fx['game']->slug)->assertInertia(fn (Assert $page) => $page
        ->where('game.icon', '/storage/games/icons/alpha.png')
        ->where('game.banner', '/storage/games/banners/alpha.jpg'));
});

it('shows the picture uploads on the admin game form', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::Moderator)->withTwoFactor()->create());
    $game = Game::factory()->create();

    Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
        ->assertOk()
        ->assertFormFieldExists('icon_path')
        ->assertFormFieldExists('cover_path')
        ->assertFormFieldExists('hero_path');
});
