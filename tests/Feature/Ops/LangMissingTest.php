<?php

declare(strict_types=1);

/*
 * lang:missing (item 17): progress per language, CSV export for translators, checked import.
 */

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/x2key-lang-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    file_put_contents($this->dir.'/en.json', json_encode(['a.one' => 'One', 'a.count' => ':count offers', 'a.two' => 'Two']));
    file_put_contents($this->dir.'/es.json', json_encode(['a.one' => 'Uno']));
    app()->useLangPath($this->dir);
});

afterEach(function (): void {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    rmdir($this->dir);
});

it('exports the strings still showing in English', function (): void {
    $this->artisan('lang:missing', ['locale' => 'es', '--export' => $this->dir.'/es.csv'])->assertSuccessful();

    $csv = (string) file_get_contents($this->dir.'/es.csv');
    expect($csv)->toContain('a.count')->toContain('a.two')->not->toContain('a.one');
});

it('imports a translated CSV and refuses broken placeholders', function (): void {
    file_put_contents($this->dir.'/es.csv', "key,english,translation\na.count,\":count offers\",\"ofertas\"\na.two,Two,Dos\nunknown,X,Y\n");

    $this->artisan('lang:missing', ['locale' => 'es', '--import' => $this->dir.'/es.csv'])->assertSuccessful();

    $es = json_decode((string) file_get_contents($this->dir.'/es.json'), true);
    expect($es)->toBe(['a.one' => 'Uno', 'a.two' => 'Dos']);
});

it('rejects an unknown language', function (): void {
    $this->artisan('lang:missing', ['locale' => 'xx'])->assertFailed();
});
