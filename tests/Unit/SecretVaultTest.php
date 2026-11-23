<?php

declare(strict_types=1);

use App\Domains\Storage\Exceptions\SecretVaultException;
use App\Domains\Storage\Vault\EnvelopeSecretVault;
use App\Domains\Storage\Vault\SealedSecret;

function vault(string $master = 'master', string $pepper = 'pepper'): EnvelopeSecretVault
{
    return new EnvelopeSecretVault(hash('sha256', $master, true), hash('sha256', $pepper, true));
}

it('round-trips a secret with a fresh data key every time', function (): void {
    $vault = vault();

    $a = $vault->seal('AAAAA-BBBBB-CCCCC');
    $b = $vault->seal('AAAAA-BBBBB-CCCCC');

    expect($vault->open($a))->toBe('AAAAA-BBBBB-CCCCC')
        ->and($a->ciphertext)->not->toBe($b->ciphertext)
        ->and($a->wrappedKey)->not->toBe($b->wrappedKey)
        ->and($a->ciphertext)->not->toContain('AAAAA')
        ->and($a->wrappedKey)->toStartWith('v1:');
});

it('refuses tampered ciphertext, a swapped data key or another master key', function (): void {
    $vault = vault();
    $sealed = $vault->seal('secret-1');
    $other = $vault->seal('secret-2');

    $raw = base64_decode($sealed->ciphertext, true);
    $raw[strlen((string) $raw) - 1] = chr(ord($raw[strlen((string) $raw) - 1]) ^ 1);

    expect(fn () => $vault->open(new SealedSecret(base64_encode((string) $raw), $sealed->wrappedKey)))->toThrow(SecretVaultException::class)
        ->and(fn () => $vault->open(new SealedSecret($sealed->ciphertext, $other->wrappedKey)))->toThrow(SecretVaultException::class)
        ->and(fn () => vault('another master')->open($sealed))->toThrow(SecretVaultException::class)
        ->and(fn () => $vault->open(new SealedSecret($sealed->ciphertext, 'v2:'.substr($sealed->wrappedKey, 3))))->toThrow(SecretVaultException::class);
});

it('fingerprints with a pepper: stable for one pepper, different across peppers', function (): void {
    expect(vault()->fingerprint('code:ABC'))->toBe(vault()->fingerprint('code:ABC'))
        ->and(vault()->fingerprint('code:ABC'))->not->toBe(hash('sha256', 'code:ABC'))
        ->and(vault(pepper: 'other')->fingerprint('code:ABC'))->not->toBe(vault()->fingerprint('code:ABC'))
        ->and(vault()->fingerprint('code:ABC'))->toMatch('/^[0-9a-f]{64}$/');
});

it('derives independent keys from the app key when none are configured', function (): void {
    $appKey = 'base64:'.base64_encode(random_bytes(32));
    $derived = EnvelopeSecretVault::fromConfig(null, '', $appKey);
    $same = EnvelopeSecretVault::fromConfig(null, null, $appKey);

    expect($same->open($derived->seal('x')))->toBe('x')
        ->and($derived->fingerprint('x'))->toBe($same->fingerprint('x'))
        ->and(fn () => EnvelopeSecretVault::fromConfig(null, null, 'base64:'.base64_encode(random_bytes(32)))->open($derived->seal('x')))->toThrow(SecretVaultException::class);
});

it('never prints sealed values when dumped', function (): void {
    $sealed = vault()->seal('topsecret');

    expect(print_r($sealed, true))->not->toContain($sealed->ciphertext)->not->toContain($sealed->wrappedKey)->toContain('[sealed]');
});
