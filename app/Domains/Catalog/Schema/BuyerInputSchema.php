<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schema;

use App\Shared\Support\Translations;
use InvalidArgumentException;

/**
 * Fields the buyer fills in at checkout for a game×category (spec 5.2 / 8.2), e.g. Player ID, Server, Gamertag.
 * Defined in M3, enforced at checkout in M6.
 *
 * Stored JSON (a list):
 *   [{"key": "player_id", "type": "text", "label": {"en": "Player ID"}, "required": true, "pattern": "^[0-9]{6,12}$",
 *     "help": {"en": "Find it in your profile"}, "options": []}]
 * Types: text | number | select. Patterns are validated as regular expressions when the schema is saved.
 */
final class BuyerInputSchema
{
    private const array TYPES = ['text', 'number', 'select'];

    /**
     * @param  list<array{key: string, type: string, label: array<string, string>, required: bool, pattern: string|null, help: array<string, string>, options: list<string>}>  $fields
     */
    private function __construct(private readonly array $fields) {}

    public static function fromArray(mixed $raw): self
    {
        if ($raw === null) {
            return new self([]);
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new InvalidArgumentException('Buyer input schema must be a list of fields.');
        }

        $fields = [];
        $seen = [];

        foreach ($raw as $i => $field) {
            if (! is_array($field)) {
                throw new InvalidArgumentException("Buyer input #{$i} must be an object.");
            }

            $key = $field['key'] ?? null;
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,47}$/', $key) !== 1 || isset($seen[$key])) {
                throw new InvalidArgumentException("Buyer input #{$i} has an invalid or duplicate key.");
            }
            $seen[$key] = true;

            $type = $field['type'] ?? null;
            if (! in_array($type, self::TYPES, true)) {
                throw new InvalidArgumentException("Buyer input [{$key}] has an unknown type.");
            }

            $label = $field['label'] ?? null;
            if (! is_array($label) || ! is_string($label['en'] ?? null) || $label['en'] === '') {
                throw new InvalidArgumentException("Buyer input [{$key}] needs an English label.");
            }

            $pattern = $field['pattern'] ?? null;
            if ($pattern !== null && $pattern !== '') {
                if (! is_string($pattern) || @preg_match('/'.str_replace('/', '\/', $pattern).'/u', '') === false) {
                    throw new InvalidArgumentException("Buyer input [{$key}] has an invalid pattern.");
                }
            } else {
                $pattern = null;
            }

            $options = [];
            if ($type === 'select') {
                $rawOptions = $field['options'] ?? null;
                if (! is_array($rawOptions) || $rawOptions === []) {
                    throw new InvalidArgumentException("Buyer input [{$key}] needs options.");
                }
                foreach ($rawOptions as $option) {
                    if (! is_string($option) || $option === '') {
                        throw new InvalidArgumentException("Buyer input [{$key}] has an invalid option.");
                    }
                    $options[] = $option;
                }
            }

            $help = $field['help'] ?? [];

            $fields[] = [
                'key' => $key,
                'type' => $type,
                'label' => self::strings($label),
                'required' => (bool) ($field['required'] ?? true),
                'pattern' => $pattern,
                'help' => is_array($help) ? self::strings($help) : [],
                'options' => $options,
            ];
        }

        return new self($fields);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, string>
     */
    private static function strings(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @return list<array{key: string, type: string, label: array<string, string>, required: bool, pattern: string|null, help: array<string, string>, options: list<string>}>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Laravel validation rules for the checkout form (used in M6).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->fields as $field) {
            $rule = [$field['required'] ? 'required' : 'nullable'];
            $rule[] = match ($field['type']) {
                'number' => 'numeric',
                default => 'string',
            };
            if ($field['type'] !== 'number') {
                $rule[] = 'max:255';
            }
            if ($field['pattern'] !== null) {
                $rule[] = 'regex:/'.str_replace('/', '\/', $field['pattern']).'/u';
            }
            if ($field['type'] === 'select') {
                $rule[] = 'in:'.implode(',', $field['options']);
            }
            $rules["buyer_inputs.{$field['key']}"] = $rule;
        }

        return $rules;
    }

    /**
     * @return list<array{key: string, type: string, label: string, required: bool, help: string, options: list<string>}>
     */
    public function present(?string $locale = null): array
    {
        return array_map(static fn (array $f): array => [
            'key' => $f['key'],
            'type' => $f['type'],
            'label' => Translations::pick($f['label'], $locale),
            'required' => $f['required'],
            'help' => Translations::pick($f['help'], $locale),
            'options' => $f['options'],
        ], $this->fields);
    }
}
