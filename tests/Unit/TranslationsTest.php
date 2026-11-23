<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\CategoryTypeCode;

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

    foreach ($en as $key => $value) {
        preg_match_all('/:\w+/', $value, $expected);
        preg_match_all('/:\w+/', $other[$key] ?? '', $actual);
        expect($actual[0])->toBe($expected[0], "Placeholder mismatch for {$key} in {$locale}");
    }
})->with(['fr', 'ar']);

it('labels all 15 category types', function (): void {
    expect(CategoryTypeCode::cases())->toHaveCount(15);

    foreach (CategoryTypeCode::cases() as $case) {
        expect(langFile('en'))->toHaveKey($case->labelKey());
    }

    expect(array_filter(CategoryTypeCode::cases(), fn (CategoryTypeCode $c) => $c->inHero()))->toHaveCount(8);
});
