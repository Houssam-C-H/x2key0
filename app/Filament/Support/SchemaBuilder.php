<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domains\Catalog\Enums\AttributeType;
use App\Domains\Catalog\Schema\AttributeSchema;
use App\Domains\Catalog\Schema\BuyerInputSchema;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use InvalidArgumentException;

/**
 * Admin builders for a game×category's attribute schema (filters) and buyer-input schema (checkout fields).
 * The same validators as the model run on submit, so staff see a form error instead of a failed save.
 */
final class SchemaBuilder
{
    public static function attributes(): Repeater
    {
        return Repeater::make('attribute_schema')
            ->label('Attributes & filters')
            ->helperText('Attributes products carry; filterable ones appear in the listing sidebar with counts.')
            ->collapsible()
            ->cloneable()
            ->reorderable()
            ->itemLabel(fn (array $state): ?string => is_string($state['key'] ?? null) ? $state['key'].' ('.(is_string($state['type'] ?? null) ? $state['type'] : '?').')' : null)
            ->schema([
                TextInput::make('key')->required()->regex('/^[a-z][a-z0-9_]{0,47}$/')->helperText('lowercase_snake_case, unique'),
                Select::make('type')->required()->live()->options(collect(AttributeType::cases())->mapWithKeys(fn (AttributeType $t): array => [$t->value => ucfirst($t->value)])->all()),
                TextInput::make('unit')->maxLength(16)->visible(fn (Get $get): bool => $get('type') === AttributeType::Number->value),
                Toggle::make('filterable')->default(true)->inline(false),
                Toggle::make('required')->default(false)->inline(false),
                Translatable::input('label', 'Label'),
                Repeater::make('options')
                    ->visible(fn (Get $get): bool => in_array($get('type'), [AttributeType::Select->value, AttributeType::MultiSelect->value], true))
                    ->schema([
                        TextInput::make('value')->required()->maxLength(64),
                        Translatable::input('label', 'Option label'),
                    ])
                    ->mutateDehydratedStateUsing(fn (?array $state): array => array_values($state ?? []))
                    ->columnSpanFull(),
            ])
            ->columns(5)
            ->mutateDehydratedStateUsing(fn (?array $state): ?array => $state === null || $state === [] ? null : array_values($state))
            ->rule(self::validator(static fn (array $list) => AttributeSchema::fromArray($list)))
            ->columnSpanFull();
    }

    public static function buyerInputs(): Repeater
    {
        return Repeater::make('buyer_input_schema')
            ->label('Buyer inputs at checkout')
            ->helperText('Fields the buyer must fill in when ordering (Player ID, server…). Validated at checkout.')
            ->collapsible()
            ->reorderable()
            ->itemLabel(fn (array $state): ?string => is_string($state['key'] ?? null) ? $state['key'] : null)
            ->schema([
                TextInput::make('key')->required()->regex('/^[a-z][a-z0-9_]{0,47}$/'),
                Select::make('type')->required()->live()->options(['text' => 'Text', 'number' => 'Number', 'select' => 'Select']),
                Toggle::make('required')->default(true)->inline(false),
                TextInput::make('pattern')->label('Regex pattern (optional)')->maxLength(255),
                Translatable::input('label', 'Label'),
                Translatable::input('help', 'Help text', required: false),
                TagsInput::make('options')->visible(fn (Get $get): bool => $get('type') === 'select')->columnSpanFull(),
            ])
            ->columns(4)
            ->mutateDehydratedStateUsing(fn (?array $state): ?array => $state === null || $state === [] ? null : array_values($state))
            ->rule(self::validator(static fn (array $list) => BuyerInputSchema::fromArray($list)))
            ->columnSpanFull();
    }

    /**
     * @param  Closure(list<mixed>): mixed  $parse
     */
    private static function validator(Closure $parse): Closure
    {
        return static function () use ($parse): Closure {
            return static function (string $attribute, mixed $value, Closure $fail) use ($parse): void {
                if ($value === null || $value === []) {
                    return;
                }

                $list = [];
                foreach (is_array($value) ? $value : [] as $item) {
                    if (is_array($item) && isset($item['options']) && is_array($item['options'])) {
                        $item['options'] = array_values($item['options']);
                    }
                    $list[] = $item;
                }

                try {
                    $parse($list);
                } catch (InvalidArgumentException $e) {
                    $fail($e->getMessage());
                }
            };
        };
    }
}
