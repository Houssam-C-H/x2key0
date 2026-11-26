<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Game;
use App\Domains\Catalog\Models\SearchMiss;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Filament\Pages\SearchAndLanguages;
use App\Shared\Support\TranslationProgress;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\CatalogFixture;

/*
 * Search misses and translation progress (docs/ADMIN_PLAN.md item 14).
 */

it('counts searches that find nothing, anonymously and normalised', function (): void {
    $this->get('/en/search?q=Zzqx%20%20Unknown')->assertOk();
    $this->get('/en/search?q=zzqx unknown')->assertOk();
    $this->get('/en/search?q=a')->assertOk(); // too short

    $miss = SearchMiss::query()->sole();
    expect($miss->query)->toBe('zzqx unknown')->and($miss->hits)->toBe(2)->and($miss->locale)->toBe('en');
});

it('turns a miss into a game alias so the next search finds the game', function (): void {
    CatalogFixture::build();
    $game = Game::query()->firstOrFail();
    $this->get('/en/search?q=qwzlegends')->assertOk();
    $miss = SearchMiss::query()->sole();
    $this->actingAs(User::factory()->staff(StaffRole::Moderator)->withTwoFactor()->create());

    Livewire::test(SearchAndLanguages::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$miss])
        ->assertSee('Translation progress')
        ->callAction(TestAction::make('alias')->table($miss), ['game_id' => $game->id])
        ->assertCanNotSeeTableRecords([$miss]);

    expect($game->refresh()->search_aliases)->toContain('qwzlegends')
        ->and($miss->refresh()->resolved_at)->not->toBeNull();
});

it('reports translation progress per language like lang:missing', function (): void {
    $rows = collect(TranslationProgress::all())->keyBy('locale');
    expect($rows->has('fr'))->toBeTrue()
        ->and($rows['fr']['kind'])->toBe('content')
        ->and($rows['fr']['missing'])->toBe(0) // content languages keep identical keys (test-enforced)
        ->and($rows->has('en'))->toBeFalse();
});
