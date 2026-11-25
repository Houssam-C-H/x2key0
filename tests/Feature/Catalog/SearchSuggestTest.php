<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Catalog\Models\Game;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Support\SearchSuggest;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Search as you type: prefixes, aliases, typos ("Did you mean"), categories; the suggest endpoint for the header.
 */

beforeEach(function (): void {
    foreach ([['fortnite', 'Fortnite', []], ['call-of-duty', 'Call of Duty', ['cod', 'warzone']], ['valorant', 'Valorant', []]] as [$slug, $name, $aliases]) {
        $game = Game::factory()->create(['slug' => $slug, 'name' => ['en' => $name], 'search_aliases' => $aliases]);
        GameCategory::factory()->for($game)->ofType(CategoryTypeCode::Accounts)->create();
    }
    $this->suggest = app(SearchSuggest::class);
});

it('matches prefixes, words and aliases', function (): void {
    expect(array_column($this->suggest->games('fort', 'en'), 'name'))->toBe(['Fortnite'])
        ->and(array_column($this->suggest->games('duty', 'en'), 'name'))->toBe(['Call of Duty'])
        ->and(array_column($this->suggest->games('cod', 'en'), 'name'))->toBe(['Call of Duty'])
        ->and($this->suggest->games('x', 'en'))->toBe([]);
});

it('tolerates typos and offers "Did you mean"', function (): void {
    expect(array_column($this->suggest->games('fortnight', 'en'), 'name'))->toBe(['Fortnite'])
        ->and(array_column($this->suggest->games('valorent', 'en'), 'name'))->toBe(['Valorant'])
        ->and($this->suggest->didYouMean('valorent', 'en'))->toBe('Valorant')
        ->and($this->suggest->didYouMean('valorant', 'en'))->toBeNull()
        ->and($this->suggest->games('zzzzzz', 'en'))->toBe([]);

    $this->get('/en/search?q=valorent')->assertInertia(fn (Assert $page) => $page->where('didYouMean', 'Valorant'));
});

it('serves header suggestions with games and categories', function (): void {
    $this->getJson('/en/search/suggest?q=acc')->assertOk()->assertJsonPath('categories.0.label', 'Accounts');
    $this->getJson('/en/search/suggest?q=cod')->assertOk()->assertJsonPath('games.0.name', 'Call of Duty')->assertJsonPath('games.0.href', '/en/call-of-duty');
});
