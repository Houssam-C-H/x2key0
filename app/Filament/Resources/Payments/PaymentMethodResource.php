<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Payments\Models\PaymentMethod;
use App\Filament\Resources\Payments\Pages\EditPaymentMethod;
use App\Filament\Resources\Payments\Pages\ListPaymentMethods;
use App\Filament\Support\MinorUnits;
use App\Filament\Support\Staff;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Checkout payment methods (docs/ADMIN_PLAN.md item 9): on/off, countries, currencies, amount limits and fees,
 * without a deploy. Methods come with their provider driver (PaymentMethodSeeder), so staff cannot add or delete
 * them here. PaymentMethod is Auditable; PaymentRouter reads these rows at checkout. Finance and admins.
 */
final class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string|UnitEnum|null $navigationGroup = 'Payments';

    protected static ?string $navigationLabel = 'Payment methods';

    protected static ?string $slug = 'payment-methods';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return Staff::has(StaffRole::Finance);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Method')->columns(3)->schema([
                TextInput::make('code')->disabled()->dehydrated(false),
                TextInput::make('provider')->disabled()->dehydrated(false),
                TextInput::make('sort')->integer()->minValue(0)->maxValue(1000)->required(),
                Toggle::make('is_enabled')->label('On at checkout')->columnSpanFull(),
            ]),
            Translatable::input('name', 'Name shown to buyers'),
            Section::make('Where and how much')->columns(2)->schema([
                TagsInput::make('countries')->label('Countries (ISO codes, empty = everywhere)')->placeholder('FR')
                    ->nestedRecursiveRules(['size:2', 'alpha'])
                    ->dehydrateStateUsing(fn (mixed $state): ?array => is_array($state) && $state !== [] ? array_values(array_map(fn (mixed $c): string => strtoupper(is_string($c) ? $c : ''), $state)) : null),
                TagsInput::make('currencies')->label('Currencies (ISO codes)')->placeholder('USD')->required()
                    ->nestedRecursiveRules(['size:3', 'alpha'])
                    ->dehydrateStateUsing(fn (mixed $state): array => is_array($state) ? array_values(array_map(fn (mixed $c): string => strtoupper(is_string($c) ? $c : ''), $state)) : []),
                MinorUnits::input('min_amount')->label('Smallest order'),
                MinorUnits::input('max_amount', required: false)->label('Largest order (empty = no limit)'),
            ]),
            Section::make('Fee charged to the buyer')->columns(2)->schema([
                TextInput::make('fee_bps')->label('Percentage, in basis points (100 = 1 %)')->integer()->minValue(0)->maxValue(2000)->required(),
                MinorUnits::input('fee_fixed')->label('Fixed part'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->state(fn (PaymentMethod $record): string => is_string($record->name['en'] ?? null) ? $record->name['en'] : $record->code),
                TextColumn::make('provider')->badge()->color('gray'),
                TextColumn::make('countries')->formatStateUsing(fn (mixed $state): string => is_array($state) && $state !== [] ? implode(', ', array_filter($state, is_string(...))) : 'everywhere')->placeholder('everywhere')->wrap(),
                TextColumn::make('currencies')->badge(),
                TextColumn::make('limits')->label('Limits')->state(fn (PaymentMethod $r): string => MinorUnits::toDecimal($r->min_amount).' – '.($r->max_amount === null ? '∞' : MinorUnits::toDecimal($r->max_amount))),
                TextColumn::make('fee')->label('Fee')->state(fn (PaymentMethod $r): string => number_format($r->fee_bps / 100, 2).' % + '.MinorUnits::toDecimal($r->fee_fixed)),
                ToggleColumn::make('is_enabled')->label('On')
                    ->afterStateUpdated(fn (PaymentMethod $record, bool $state) => Notification::make()->success()->title($record->code.' '.($state ? 'on' : 'off'))->send()),
            ])
            ->recordActions([EditAction::make()]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPaymentMethods::route('/'),
            'edit' => EditPaymentMethod::route('/{record}/edit'),
        ];
    }
}
