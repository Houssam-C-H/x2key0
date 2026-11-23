<?php

declare(strict_types=1);

use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Storage\Enums\StorageItemType;
use App\Domains\Storage\Support\StoragePayload;

it('parses one key per line, skipping blanks and comments, and keeps the original text', function (): void {
    $parsed = StoragePayload::parse(StorageItemType::Key, "# my batch\r\nabcde-fghij\n\n  KLMNO PQRST  \n".str_repeat('x', 1001));

    expect($parsed['invalid'])->toBe(1)
        ->and(array_column($parsed['items'], 'plaintext'))->toBe(['abcde-fghij', 'KLMNO PQRST'])
        ->and(array_column($parsed['items'], 'normalised'))->toBe(['code:ABCDEFGHIJ', 'code:KLMNOPQRST']);
});

it('normalises keys so case, spaces and dashes do not make a different key', function (): void {
    expect(StoragePayload::normalise(StorageItemType::Key, 'ab-cd ef'))->toBe(StoragePayload::normalise(StorageItemType::GiftCard, 'ABCDEF'))
        ->and(StoragePayload::normalise(StorageItemType::Text, "  hello \n world "))->toBe('text:hello world');
});

it('parses accounts from pipe or tab separated lines; login and password are required', function (): void {
    $parsed = StoragePayload::parse(StorageItemType::Account, "Hero01|pw1|hero@example.com\nonlylogin\nhero02\tpw2\na|b|c|d|e|f|g");

    expect($parsed['invalid'])->toBe(2)
        ->and(count($parsed['items']))->toBe(2)
        ->and(json_decode($parsed['items'][0]['plaintext'], true))->toBe([
            'login' => 'Hero01', 'password' => 'pw1', 'email' => 'hero@example.com', 'email_password' => null, 'recovery_codes' => null, 'notes' => null,
        ])
        ->and($parsed['items'][0]['normalised'])->toBe('account:hero01');
});

it('builds an account from the form', function (): void {
    expect(StoragePayload::account(['login' => ' Hero ', 'password' => 'pw']))->not->toBeNull()
        ->and(StoragePayload::account(['login' => 'Hero', 'password' => '']))->toBeNull();
});

it('only allows the transitions of spec 6.2', function (OfferStatus $from, OfferStatus $to, bool $allowed): void {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'review → active' => [OfferStatus::PendingReview, OfferStatus::Active, true],
    'review → rejected' => [OfferStatus::PendingReview, OfferStatus::Rejected, true],
    'active ⇄ deactivated' => [OfferStatus::Active, OfferStatus::Deactivated, true],
    'active → expired' => [OfferStatus::Active, OfferStatus::Expired, true],
    'out of stock → active' => [OfferStatus::OutOfStock, OfferStatus::Active, true],
    'expired → active (relist)' => [OfferStatus::Expired, OfferStatus::Active, true],
    'system deactivated → active' => [OfferStatus::SystemDeactivated, OfferStatus::Active, false],
    'system deactivated → deactivated (restore)' => [OfferStatus::SystemDeactivated, OfferStatus::Deactivated, true],
    'rejected → deactivated' => [OfferStatus::Rejected, OfferStatus::Deactivated, false],
    'pending review → deactivated' => [OfferStatus::PendingReview, OfferStatus::Deactivated, false],
]);
