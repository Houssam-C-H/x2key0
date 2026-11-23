<?php

declare(strict_types=1);

namespace App\Domains\Storage\Support;

use App\Domains\Storage\Enums\StorageItemType;
use SensitiveParameter;

/**
 * Parsing and normalisation of storage imports (spec 8.1: "paste, CSV/TXT, or account form; normalise").
 *
 * Keys, codes, gift cards and text: one item per line. Accounts: one per line,
 * `login|password|email|email_password|recovery_codes|notes` (a tab works as separator too); login and
 * password are required. Empty lines and lines starting with `#` are skipped.
 *
 * The normalised form only feeds the duplicate fingerprint: the seller's original text is what is sealed.
 */
final class StoragePayload
{
    public const int MAX_LINE = 1000;

    public const array ACCOUNT_FIELDS = ['login', 'password', 'email', 'email_password', 'recovery_codes', 'notes'];

    /**
     * @return array{items: list<array{plaintext: string, normalised: string}>, invalid: int}
     */
    public static function parse(StorageItemType $type, #[SensitiveParameter] string $input): array
    {
        $items = [];
        $invalid = 0;

        foreach (preg_split('/\r\n|\r|\n/', $input) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $item = mb_strlen($line) > self::MAX_LINE ? null : self::line($type, $line);

            if ($item === null) {
                $invalid++;
            } else {
                $items[] = $item;
            }
        }

        return ['items' => $items, 'invalid' => $invalid];
    }

    /**
     * One account from the structured form.
     *
     * @param  array<string, string|null>  $fields
     * @return array{plaintext: string, normalised: string}|null
     */
    public static function account(#[SensitiveParameter] array $fields): ?array
    {
        $clean = [];

        foreach (self::ACCOUNT_FIELDS as $key) {
            $value = trim((string) ($fields[$key] ?? ''));
            if (mb_strlen($value) > self::MAX_LINE) {
                return null;
            }
            $clean[$key] = $value === '' ? null : $value;
        }

        if ($clean['login'] === null || $clean['password'] === null) {
            return null;
        }

        return [
            'plaintext' => json_encode($clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'normalised' => 'account:'.mb_strtolower($clean['login']),
        ];
    }

    /** Fingerprint input for a single secret: case, spaces and dashes do not make a key different. */
    public static function normalise(StorageItemType $type, #[SensitiveParameter] string $value): string
    {
        return match ($type) {
            StorageItemType::Key, StorageItemType::Code, StorageItemType::GiftCard => 'code:'.mb_strtoupper((string) preg_replace('/[\s\-]+/u', '', $value)),
            StorageItemType::Text => 'text:'.(string) preg_replace('/\s+/u', ' ', trim($value)),
            StorageItemType::Account => 'account:'.mb_strtolower(trim($value)),
        };
    }

    /**
     * @return array{plaintext: string, normalised: string}|null
     */
    private static function line(StorageItemType $type, #[SensitiveParameter] string $line): ?array
    {
        if ($type !== StorageItemType::Account) {
            return ['plaintext' => $line, 'normalised' => self::normalise($type, $line)];
        }

        $parts = array_map('trim', preg_split('/\t|\|/', $line) ?: []);

        if (count($parts) > count(self::ACCOUNT_FIELDS)) {
            return null;
        }

        return self::account(array_combine(
            array_slice(self::ACCOUNT_FIELDS, 0, count($parts)),
            $parts,
        ));
    }
}
