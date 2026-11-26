<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Http\Middleware\ResolveLocale;

function langFile(string $locale): array
{
    return json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
}

it('has every English key in each other locale, and no extras', function (string $locale): void {
    $en = array_keys(langFile('en'));
    $other = array_keys(langFile($locale));

    expect(array_values(array_diff($en, $other)))->toBe([], "Missing in {$locale}")
        ->and(array_values(array_diff($other, $en)))->toBe([], "Extra in {$locale}");
})->with(['fr', 'ar']);

it('keeps :placeholders intact in translations', function (string $locale): void {
    $en = langFile('en');
    $other = langFile($locale);

    foreach ($other as $key => $value) {
        preg_match_all('/:\w+/', $en[$key] ?? '', $expected);
        preg_match_all('/:\w+/', $value, $actual);
        expect($actual[0])->toBe($expected[0], "Placeholder mismatch for {$key} in {$locale}");
    }
})->with(['fr', 'ar', 'es', 'de', 'pt', 'tr']);

it('gives interface-only languages the core screens and no unknown keys', function (string $locale): void {
    $en = langFile('en');
    $other = langFile($locale);

    expect(array_values(array_diff(array_keys($other), array_keys($en))))->toBe([], "Extra in {$locale}");

    foreach (['header.', 'home.hero', 'footer.', 'cookie.', 'locale.', 'megamenu.'] as $prefix) {
        foreach ($en as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                expect($other)->toHaveKey($key);
            }
        }
    }
})->with(['es', 'de', 'pt', 'tr']);

it('has a language file for every configured locale and keeps content languages fully translated', function (): void {
    foreach (array_keys(config('x2key.locales')) as $code) {
        expect(file_exists(lang_path("{$code}.json")))->toBeTrue("lang/{$code}.json");
    }

    expect(ResolveLocale::contentLocales())->toBe(['en', 'fr', 'ar']);
});

it('labels all 15 category types', function (): void {
    expect(CategoryTypeCode::cases())->toHaveCount(15);

    foreach (CategoryTypeCode::cases() as $case) {
        expect(langFile('en'))->toHaveKey($case->labelKey());
    }

    expect(array_filter(CategoryTypeCode::cases(), fn (CategoryTypeCode $c) => $c->inHero()))->toHaveCount(8);
});
