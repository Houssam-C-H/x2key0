<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schema;

use App\Domains\Catalog\Enums\AttributeType;
use App\Shared\Support\Translations;
use InvalidArgumentException;

/**
 * Attribute schema of a game×category: which attributes products carry and which filters the
 * listing shows (spec 1.4.1, e.g. Fortnite Accounts: Accounts Type, V-Bucks Amount, Full Mail Access…).
 *
 * Stored JSON (a list):
 *   [{"key": "account_type", "type": "select", "label": {"en": "Account type"},
 *     "options": [{"value": "skin", "label": {"en": "Skin account"}}], "filterable": true, "required": false, "unit": null}]
 *
 * Keys are lowercase snake_case and unique. Select/multiselect need options; numbers may set a unit.
 */
final class AttributeSchema
{
    private const string KEY_PATTERN = '/^[a-z][a-z0-9_]{0,47}$/';

    /**
     * @param  list<array{key: string, type: AttributeType, label: array<string, string>, options: list<array{value: string, label: array<string, string>}>, filterable: bool, required: bool, unit: string|null}>  $fields
     */
    private function __construct(private readonly array $fields) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Parses and validates stored JSON. Throws on any malformed field so bad schemas never reach the listing.
     */
    public static function fromArray(mixed $raw): self
    {
        if ($raw === null) {
            return self::empty();
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new InvalidArgumentException('Attribute schema must be a list of fields.');
        }

        $fields = [];
        $seen = [];

        foreach ($raw as $i => $field) {
            if (! is_array($field)) {
                throw new InvalidArgumentException("Field #{$i} must be an object.");
            }

            $key = $field['key'] ?? null;
            if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new InvalidArgumentException("Field #{$i} has an invalid key.");
            }
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Duplicate field key [{$key}].");
            }
            $seen[$key] = true;

            $type = AttributeType::tryFrom(is_string($field['type'] ?? null) ? $field['type'] : '');
            if ($type === null) {
                throw new InvalidArgumentException("Field [{$key}] has an unknown type.");
            }

            $label = self::labels($field['label'] ?? null, "Field [{$key}] label");

            $options = [];
            if (in_array($type, [AttributeType::Select, AttributeType::MultiSelect], true)) {
                $rawOptions = $field['options'] ?? null;
                if (! is_array($rawOptions) || $rawOptions === []) {
                    throw new InvalidArgumentException("Field [{$key}] needs options.");
                }
                $values = [];
                foreach ($rawOptions as $option) {
                    $value = is_array($option) ? ($option['value'] ?? null) : null;
                    if (! is_string($value) || $value === '' || isset($values[$value])) {
                        throw new InvalidArgumentException("Field [{$key}] has an invalid or duplicate option.");
                    }
                    $values[$value] = true;
                    $options[] = ['value' => $value, 'label' => self::labels($option['label'] ?? $value, "Option [{$value}] label")];
                }
            }

            $unit = $field['unit'] ?? null;

            $fields[] = [
                'key' => $key,
                'type' => $type,
                'label' => $label,
                'options' => $options,
                'filterable' => $type !== AttributeType::Text && (bool) ($field['filterable'] ?? true),
                'required' => (bool) ($field['required'] ?? false),
                'unit' => is_string($unit) && $unit !== '' ? $unit : null,
            ];
        }

        return new self($fields);
    }

    /**
     * @return list<array{key: string, type: AttributeType, label: array<string, string>, options: list<array{value: string, label: array<string, string>}>, filterable: bool, required: bool, unit: string|null}>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @return list<array{key: string, type: AttributeType, label: array<string, string>, options: list<array{value: string, label: array<string, string>}>, filterable: bool, required: bool, unit: string|null}>
     */
    public function filterable(): array
    {
        return array_values(array_filter($this->fields, static fn (array $f): bool => $f['filterable']));
    }

    /**
     * @return array{key: string, type: AttributeType, label: array<string, string>, options: list<array{value: string, label: array<string, string>}>, filterable: bool, required: bool, unit: string|null}|null
     */
    public function field(string $key): ?array
    {
        foreach ($this->fields as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Validates and normalises product attribute values against the schema.
     * Unknown keys are rejected; numbers become ints/floats; booleans become bool; multiselect becomes a list.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string|int|float|bool|list<string>>
     */
    public function normalise(array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            if ($this->field((string) $key) === null) {
                throw new InvalidArgumentException("Unknown attribute [{$key}].");
            }
        }

        foreach ($this->fields as $field) {
            $key = $field['key'];
            $value = $values[$key] ?? null;

            if ($value === null || $value === '' || $value === []) {
                if ($field['required']) {
                    throw new InvalidArgumentException("Attribute [{$key}] is required.");
                }

                continue;
            }

            $allowed = array_column($field['options'], 'value');

            $out[$key] = match ($field['type']) {
                AttributeType::Select => in_array($value, $allowed, true)
                    ? (string) $value
                    : throw new InvalidArgumentException("Attribute [{$key}] has an invalid option."),
                AttributeType::MultiSelect => self::multi($key, $value, $allowed),
                AttributeType::Number => is_numeric($value)
                    ? $value + 0
                    : throw new InvalidArgumentException("Attribute [{$key}] must be a number."),
                AttributeType::Boolean => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                    ?? throw new InvalidArgumentException("Attribute [{$key}] must be yes or no."),
                AttributeType::Text => is_scalar($value)
                    ? mb_substr((string) $value, 0, 500)
                    : throw new InvalidArgumentException("Attribute [{$key}] must be text."),
            };
        }

        return $out;
    }

    /**
     * Translated, serialisable form for the frontend filter sidebar.
     *
     * @return list<array{key: string, type: string, label: string, unit: string|null, options: list<array{value: string, label: string}>}>
     */
    public function presentFilters(?string $locale = null): array
    {
        return array_map(static fn (array $f): array => [
            'key' => $f['key'],
            'type' => $f['type']->value,
            'label' => Translations::pick($f['label'], $locale),
            'unit' => $f['unit'],
            'options' => array_map(static fn (array $o): array => [
                'value' => $o['value'],
                'label' => Translations::pick($o['label'], $locale),
            ], $f['options']),
        ], $this->filterable());
    }

    /**
     * @return array<string, string>
     */
    private static function labels(mixed $raw, string $context): array
    {
        if (is_string($raw) && $raw !== '') {
            return ['en' => $raw];
        }

        if (! is_array($raw) || ! is_string($raw['en'] ?? null) || $raw['en'] === '') {
            throw new InvalidArgumentException("{$context} needs at least an English text.");
        }

        return array_filter($raw, static fn (mixed $v, mixed $k): bool => is_string($k) && is_string($v) && $v !== '', ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function multi(string $key, mixed $value, array $allowed): array
    {
        // Admin key/value inputs send "a, b"; the API sends a list.
        $list = match (true) {
            is_array($value) => array_values($value),
            is_string($value) => array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== '')),
            default => [$value],
        };

        foreach ($list as $item) {
            if (! in_array($item, $allowed, true)) {
                throw new InvalidArgumentException("Attribute [{$key}] has an invalid option.");
            }
        }

        /** @var list<string> $list */
        return array_values(array_unique($list));
    }
}
