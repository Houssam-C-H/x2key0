<?php

declare(strict_types=1);

use App\Domains\Catalog\Schema\AttributeSchema;
use App\Domains\Catalog\Schema\BuyerInputSchema;
use Tests\Support\CatalogFixture;

it('parses a valid schema; text fields are never filterable', function (): void {
    $schema = AttributeSchema::fromArray(CatalogFixture::schema());

    expect($schema->fields())->toHaveCount(5)
        ->and(array_column($schema->filterable(), 'key'))->toBe(['edition', 'genres', 'multiplayer', 'release_year']);
});

it('rejects malformed schemas', function (mixed $raw, string $message): void {
    expect(fn () => AttributeSchema::fromArray($raw))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'not a list' => [['key' => 'x'], 'list'],
    'bad key' => [[['key' => 'Bad-Key', 'type' => 'text', 'label' => ['en' => 'X']]], 'invalid key'],
    'duplicate key' => [[['key' => 'a', 'type' => 'text', 'label' => 'A'], ['key' => 'a', 'type' => 'text', 'label' => 'A']], 'Duplicate'],
    'unknown type' => [[['key' => 'a', 'type' => 'color', 'label' => 'A']], 'unknown type'],
    'no english label' => [[['key' => 'a', 'type' => 'text', 'label' => ['fr' => 'A']]], 'English'],
    'select without options' => [[['key' => 'a', 'type' => 'select', 'label' => 'A']], 'needs options'],
    'duplicate option' => [[['key' => 'a', 'type' => 'select', 'label' => 'A', 'options' => [['value' => 'x'], ['value' => 'x']]]], 'duplicate option'],
]);

it('normalises product values: types coerced, unknown and invalid rejected', function (): void {
    $schema = AttributeSchema::fromArray(CatalogFixture::schema());

    expect($schema->normalise(['edition' => 'deluxe', 'genres' => 'action, rpg', 'multiplayer' => 'yes', 'release_year' => '2021', 'notes' => 'hi']))
        ->toBe(['edition' => 'deluxe', 'genres' => ['action', 'rpg'], 'multiplayer' => true, 'release_year' => 2021, 'notes' => 'hi']);

    expect(fn () => $schema->normalise(['color' => 'red']))->toThrow(InvalidArgumentException::class, 'Unknown attribute')
        ->and(fn () => $schema->normalise(['edition' => 'gold']))->toThrow(InvalidArgumentException::class, 'invalid option')
        ->and(fn () => $schema->normalise(['genres' => ['action', 'horror']]))->toThrow(InvalidArgumentException::class, 'invalid option')
        ->and(fn () => $schema->normalise(['release_year' => 'soon']))->toThrow(InvalidArgumentException::class, 'number')
        ->and(fn () => $schema->normalise(['multiplayer' => 'maybe']))->toThrow(InvalidArgumentException::class, 'yes or no');
});

it('enforces required attributes', function (): void {
    $schema = AttributeSchema::fromArray([['key' => 'server', 'type' => 'text', 'label' => 'Server', 'required' => true]]);

    expect(fn () => $schema->normalise([]))->toThrow(InvalidArgumentException::class, 'required');
});

it('presents translated filters with English fallback', function (): void {
    $filters = AttributeSchema::fromArray(CatalogFixture::schema())->presentFilters('fr');

    expect($filters[0]['label'])->toBe('Édition')
        ->and($filters[1]['label'])->toBe('Genre')
        ->and($filters[0]['options'][1])->toBe(['value' => 'deluxe', 'label' => 'Deluxe']);
});

it('builds checkout validation rules from the buyer-input schema', function (): void {
    $schema = BuyerInputSchema::fromArray([
        ['key' => 'player_id', 'type' => 'text', 'label' => ['en' => 'Player ID'], 'required' => true, 'pattern' => '^[0-9]{6,12}$'],
        ['key' => 'server', 'type' => 'select', 'label' => ['en' => 'Server'], 'required' => false, 'options' => ['eu', 'na']],
    ]);

    expect($schema->rules())->toBe([
        'buyer_inputs.player_id' => ['required', 'string', 'max:255', 'regex:/^[0-9]{6,12}$/u'],
        'buyer_inputs.server' => ['nullable', 'string', 'max:255', 'in:eu,na'],
    ]);

    $validator = validator(['buyer_inputs' => ['player_id' => '12ab']], $schema->rules());
    expect($validator->fails())->toBeTrue();
});

it('rejects invalid buyer-input patterns and types', function (array $field): void {
    expect(fn () => BuyerInputSchema::fromArray([$field]))->toThrow(InvalidArgumentException::class);
})->with([
    [['key' => 'a', 'type' => 'text', 'label' => ['en' => 'A'], 'pattern' => '([unclosed']],
    [['key' => 'a', 'type' => 'date', 'label' => ['en' => 'A']]],
    [['key' => 'a', 'type' => 'select', 'label' => ['en' => 'A']]],
]);
