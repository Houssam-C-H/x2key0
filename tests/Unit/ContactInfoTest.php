<?php

declare(strict_types=1);

use App\Shared\Support\ContactInfo;

it('finds off-platform contact details', function (string $text): void {
    expect(ContactInfo::found($text))->toBeTrue();
})->with([
    'email' => 'mail deals@example.com',
    'obfuscated email' => 'deals [at] example [dot] com',
    'url' => 'see https://cheap.example/x',
    'www' => 'www.keys-shop.net',
    'bare domain' => 'order on fastkeys.gg',
    'telegram link' => 't.me/fastkeys',
    'whatsapp link' => 'wa.me/212600000000',
    'discord invite' => 'discord.gg/abc',
    'messenger handle' => 'Telegram: @fastkeys',
    'phone intl' => 'call +212 600 000 000',
    'phone dots' => '06.12.34.56.78',
]);

it('leaves normal store text alone', function (string $text): void {
    expect(ContactInfo::found($text))->toBeFalse();
})->with([
    'plain' => 'Fast delivery on Steam keys, 24/7.',
    'numbers' => 'Over 5000 orders, 99.8% positive',
    'french' => 'Livraison en 10 minutes, merci !',
    'arabic' => 'توصيل سريع خلال ١٠ دقائق',
    'level names' => 'Gold seller since 2024',
    'times' => 'Open 09:00-21:00 UTC',
]);
