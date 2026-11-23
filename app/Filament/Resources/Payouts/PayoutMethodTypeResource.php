<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payouts;

use App\Domains\Payouts\Models\PayoutMethodType;
use App\Filament\Resources\Payouts\Pages\EditPayoutMethodType;
use App\Filament\Resources\Payouts\Pages\ListPayoutMethodTypes;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Payout method catalog (spec 5.16): finance sets fees, minimums and availability (audited). */
final class PayoutMethodTypeResource extends Resource
{
    protected static ?string $model = PayoutMethodType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Ledger';

    protected static ?string $navigationLabel = 'Payout methods';

    protected static ?string $slug = 'payout-methods';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(64),
            TextInput::make('fee_bps')->label('Fee (bps)')->numeric()->minValue(0)->maxValue(2000)->required(),
            TextInput::make('fee_fixed_amount')->label('Fixed fee (cents)')->numeric()->minValue(0)->required(),
            TextInput::make('min_amount')->label('Minimum (cents)')->numeric()->minValue(0)->required(),
            TextInput::make('sort')->numeric()->default(0),
            Toggle::make('is_enabled')->label('Enabled'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->paginated(false)
            ->columns([
                TextColumn::make('code')->badge(),
                TextColumn::make('name'),
                TextColumn::make('fee_bps')->label('Fee')->formatStateUsing(fn (PayoutMethodType $record): string => ($record->fee_bps / 100).'% + '.number_format($record->fee_fixed_amount / 100, 2)),
                TextColumn::make('min_amount')->label('Minimum')->formatStateUsing(fn (int $state): string => number_format($state / 100, 2)),
                IconColumn::make('is_enabled')->label('Enabled')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPayoutMethodTypes::route('/'),
            'edit' => EditPayoutMethodType::route('/{record}/edit'),
        ];
    }
}
